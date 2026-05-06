<?php

declare(strict_types=1);

use Opis\JsonSchema\Validator as OpisValidator;
use Opis\JsonSchema\Helper as OpisHelper;

class MageAustralia_AiReports_Model_QueryPlanValidator
{
    public function __construct(
        private MageAustralia_AiReports_Model_PrimitiveRegistry $registry,
    ) {}

    /**
     * @param array<string, mixed> $plan
     * @param int[] $userAccessibleStoreIds
     * @return array{effectiveStoreIds: int[], scopeWarning: bool, plan: array<string, mixed>}
     */
    public function validate(array $plan, array $userAccessibleStoreIds): array
    {
        $primitiveName = $plan['primitive'] ?? null;
        if (!is_string($primitiveName)) {
            throw new \InvalidArgumentException('Plan missing primitive name');
        }
        if (!array_key_exists($primitiveName, $this->registry->all())) {
            throw new \InvalidArgumentException("Unknown primitive: $primitiveName");
        }

        $primitive = $this->registry->get($primitiveName);
        $args = $plan['args'] ?? [];

        $opis = new OpisValidator();
        $schema = OpisHelper::toJSON($primitive->getArgsSchema());
        $data   = OpisHelper::toJSON($args);
        $result = $opis->validate($data, $schema);
        if (!$result->isValid()) {
            $err = $result->error();
            $msg = $err ? $err->message() : 'unknown schema error';
            throw new \InvalidArgumentException("Args failed schema: $msg");
        }

        $requested = $args['store_ids'] ?? null;
        if ($requested === null) {
            $effective = $userAccessibleStoreIds;
            $warning = false;
        } else {
            $intersection = array_values(array_intersect($requested, $userAccessibleStoreIds));
            $warning = count($intersection) !== count($requested);
            $effective = $intersection;
        }

        return [
            'effectiveStoreIds' => $effective,
            'scopeWarning'      => $warning,
            'plan'              => $plan,
        ];
    }
}
