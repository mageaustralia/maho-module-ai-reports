<?php

declare(strict_types=1);

namespace MageAustralia\AiReports\Tests\Unit;

use MageAustralia_AiReports_Model_PrimitiveRegistry as PrimitiveRegistry;
use MageAustralia_AiReports_Model_PrimitiveInterface as PrimitiveInterface;
use MageAustralia_AiReports_Model_PromptBuilder as PromptBuilder;
use PHPUnit\Framework\TestCase;

final class PromptBuilderTest extends TestCase
{
    public function testBuildIncludesPrimitiveCatalogAndStoreAcl(): void
    {
        $reg = new PrimitiveRegistry();
        $reg->register($this->stub('top_n', 'Returns top N records'));
        $reg->register($this->stub('growth', 'Returns period-over-period growth'));

        $builder = new PromptBuilder($reg, new \DateTimeImmutable('2026-05-06'));
        $prompt = $builder->build([1, 2, 3]);

        $this->assertStringContainsString('top_n', $prompt);
        $this->assertStringContainsString('Returns top N records', $prompt);
        $this->assertStringContainsString('growth', $prompt);
        $this->assertStringContainsString('2026-05-06', $prompt);
        $this->assertStringContainsString('1, 2, 3', $prompt);
        $this->assertStringContainsString('Respond with valid JSON only', $prompt);
    }

    public function testTopLevelEnvelopeSchemaIncluded(): void
    {
        $reg = new PrimitiveRegistry();
        $reg->register($this->stub('top_n'));
        $builder = new PromptBuilder($reg, new \DateTimeImmutable('2026-05-06'));
        $prompt = $builder->build([1]);
        $this->assertStringContainsString('"primitive"', $prompt);
        $this->assertStringContainsString('"args"', $prompt);
        $this->assertStringContainsString('"title"', $prompt);
        $this->assertStringContainsString('"narrative"', $prompt);
    }

    private function stub(string $name, string $description = 'desc'): PrimitiveInterface
    {
        return new class($name, $description) implements PrimitiveInterface {
            public function __construct(private string $name, private string $description) {}
            public function getName(): string { return $this->name; }
            public function getDescription(): string { return $this->description; }
            public function getArgsSchema(): array { return ['type' => 'object']; }
            public function execute(array $a, array $s): array { return []; }
            public function getDefaultRender(): array { return ['primary' => 'table']; }
        };
    }
}
