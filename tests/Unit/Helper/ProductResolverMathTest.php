<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit\Helper;

use MageAustralia_AiReports_Helper_ProductResolver as Resolver;
use PHPUnit\Framework\TestCase;

final class ProductResolverMathTest extends TestCase
{
    public function testCosineIdentical(): void
    {
        $r = $this->resolver();
        $a = [1.0, 2.0, 3.0];
        $this->assertEqualsWithDelta(
            1.0,
            $r->cosineSimilarity($a, $a, $r->norm($a), $r->norm($a)),
            0.0001,
        );
    }

    public function testCosineOrthogonal(): void
    {
        $r = $this->resolver();
        $a = [1.0, 0.0];
        $b = [0.0, 1.0];
        $this->assertEqualsWithDelta(
            0.0,
            $r->cosineSimilarity($a, $b, $r->norm($a), $r->norm($b)),
            0.0001,
        );
    }

    public function testCosineOpposite(): void
    {
        $r = $this->resolver();
        $a = [1.0, 0.0];
        $b = [-1.0, 0.0];
        $this->assertEqualsWithDelta(
            -1.0,
            $r->cosineSimilarity($a, $b, $r->norm($a), $r->norm($b)),
            0.0001,
        );
    }

    public function testNormZero(): void
    {
        $r = $this->resolver();
        $this->assertSame(0.0, $r->norm([0.0, 0.0, 0.0]));
    }

    public function testNormKnownVector(): void
    {
        $r = $this->resolver();
        // norm([3, 4]) = 5
        $this->assertEqualsWithDelta(5.0, $r->norm([3.0, 4.0]), 0.0001);
    }

    public function testCosineZeroNormReturnsZero(): void
    {
        $r = $this->resolver();
        $a = [1.0, 0.0];
        $z = [0.0, 0.0];
        $this->assertSame(0.0, $r->cosineSimilarity($a, $z, $r->norm($a), $r->norm($z)));
        $this->assertSame(0.0, $r->cosineSimilarity($z, $a, $r->norm($z), $r->norm($a)));
    }

    public function testCosineMismatchedLengthsUsesShorter(): void
    {
        $r = $this->resolver();
        // [1,0,0] vs [1,0] -- only the first two dims are compared, dot=1, both norms are 1
        $a = [1.0, 0.0, 0.0];
        $b = [1.0, 0.0];
        $similarity = $r->cosineSimilarity($a, $b, $r->norm($a), $r->norm($b));
        $this->assertEqualsWithDelta(1.0, $similarity, 0.0001);
    }

    public function testUnwrapStringInputShape(): void
    {
        // Helper returns float[] when called with string input
        $vec = [0.1, 0.2, 0.3, 0.4];
        $this->assertSame($vec, Resolver::unwrapEmbedResult($vec));
    }

    public function testUnwrapArrayInputShape(): void
    {
        // Helper returns float[][] when called with array input - take first vector
        $batch = [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]];
        $this->assertSame([0.1, 0.2, 0.3], Resolver::unwrapEmbedResult($batch));
    }

    public function testUnwrapEmptyReturnsNull(): void
    {
        $this->assertNull(Resolver::unwrapEmbedResult([]));
    }

    public function testUnwrapNonArrayReturnsNull(): void
    {
        $this->assertNull(Resolver::unwrapEmbedResult(null));
        $this->assertNull(Resolver::unwrapEmbedResult('oops'));
    }

    public function testUnwrapMalformedReturnsNull(): void
    {
        // Array of non-numeric, non-array values
        $this->assertNull(Resolver::unwrapEmbedResult(['not', 'numbers']));
        // Array containing an empty inner array
        $this->assertNull(Resolver::unwrapEmbedResult([[]]));
    }

    private function resolver(): Resolver
    {
        return new Resolver();
    }
}
