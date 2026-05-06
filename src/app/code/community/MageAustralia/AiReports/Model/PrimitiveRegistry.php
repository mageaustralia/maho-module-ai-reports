<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Model_PrimitiveRegistry
{
    /** @var array<string, MageAustralia_AiReports_Model_PrimitiveInterface> */
    private array $primitives = [];

    public function register(MageAustralia_AiReports_Model_PrimitiveInterface $p): void
    {
        $name = $p->getName();
        if (isset($this->primitives[$name])) {
            throw new \RuntimeException("Primitive already registered: $name");
        }
        $this->primitives[$name] = $p;
    }

    public function get(string $name): MageAustralia_AiReports_Model_PrimitiveInterface
    {
        if (!isset($this->primitives[$name])) {
            throw new \RuntimeException("Unknown primitive: $name");
        }
        return $this->primitives[$name];
    }

    /** @return array<string, MageAustralia_AiReports_Model_PrimitiveInterface> */
    public function all(): array
    {
        return $this->primitives;
    }
}
