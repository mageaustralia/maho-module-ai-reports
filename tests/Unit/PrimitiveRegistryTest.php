<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit;

use MageAustralia_AiReports_Model_PrimitiveInterface as PrimitiveInterface;
use MageAustralia_AiReports_Model_PrimitiveRegistry as PrimitiveRegistry;
use PHPUnit\Framework\TestCase;

final class PrimitiveRegistryTest extends TestCase
{
    public function testRegisterAndGet(): void
    {
        $primitive = $this->fakePrimitive('top_n');
        $registry = new PrimitiveRegistry();
        $registry->register($primitive);

        $this->assertSame($primitive, $registry->get('top_n'));
    }

    public function testAllReturnsAllRegistered(): void
    {
        $registry = new PrimitiveRegistry();
        $registry->register($this->fakePrimitive('a'));
        $registry->register($this->fakePrimitive('b'));
        $this->assertSame(['a', 'b'], array_keys($registry->all()));
    }

    public function testDuplicateRegistrationThrows(): void
    {
        $registry = new PrimitiveRegistry();
        $registry->register($this->fakePrimitive('top_n'));
        $this->expectException(\RuntimeException::class);
        $registry->register($this->fakePrimitive('top_n'));
    }

    public function testGetUnknownThrows(): void
    {
        $registry = new PrimitiveRegistry();
        $this->expectException(\RuntimeException::class);
        $registry->get('nope');
    }

    private function fakePrimitive(string $name): PrimitiveInterface
    {
        return new class($name) implements PrimitiveInterface {
            public function __construct(private string $name) {}
            public function getName(): string { return $this->name; }
            public function getDescription(): string { return 'fake'; }
            public function getArgsSchema(): array { return ['type' => 'object']; }
            public function execute(array $args, array $scopeStoreIds): array { return []; }
            public function getDefaultRender(): array { return ['primary' => 'table']; }
        };
    }
}
