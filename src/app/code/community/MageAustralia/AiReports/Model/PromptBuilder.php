<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

class MageAustralia_AiReports_Model_PromptBuilder
{
    public function __construct(
        private MageAustralia_AiReports_Model_PrimitiveRegistry $registry,
        private \DateTimeImmutable $today,
    ) {}

    /**
     * @param int[]                                              $userAccessibleStoreIds
     * @param array<int, array{id: int, name: string, similarity: float}> $resolvedProducts
     */
    public function build(array $userAccessibleStoreIds, array $resolvedProducts = []): string
    {
        $todayIso = $this->today->format('Y-m-d');
        $stores   = implode(', ', $userAccessibleStoreIds);

        $catalog = '';
        foreach ($this->registry->all() as $primitive) {
            $catalog .= "## " . $primitive->getName() . "\n";
            $catalog .= $primitive->getDescription() . "\n";
            $catalog .= "Args schema:\n```json\n";
            $catalog .= json_encode($primitive->getArgsSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $catalog .= "\n```\n\n";
        }

        $productSection = '';
        if (!empty($resolvedProducts)) {
            $lines = [];
            foreach ($resolvedProducts as $p) {
                $lines[] = '- ' . $p['name'] . ' (id=' . $p['id'] . ')';
            }
            $productList    = implode("\n", $lines);
            $productSection = <<<PRODUCTS

Resolved product mentions (the user may be asking about these specific products - use the `product_ids` arg if your query is about them, otherwise ignore):
$productList

PRODUCTS;
        }

        return <<<PROMPT
You are a reporting assistant for an e-commerce admin. Your job is to translate the user's natural-language question into a structured query plan that selects exactly one of the available primitives below.

Today's date is $todayIso. The user has access to stores with IDs: $stores. If the user does not specify a store, leave store_ids null (the system will scope to the user's allowed stores).
$productSection
Available primitives:

$catalog

Respond with valid JSON only matching this top-level schema. No markdown fences, no prose:

```json
{
  "primitive": "<one of the primitive names above>",
  "args": { /* matching that primitive's args schema */ },
  "render_hint": { "primary": "<bar_chart|line_chart|pie_chart|table|kpi>", "secondary": "<optional>" },
  "title": "<short human-readable title>",
  "narrative": "<1-2 sentence explanation of what the report shows>"
}
```

Period guidance:
- Prefer relative periods (e.g. "last_complete_month") when the user says "last month" or "this week".
- Use absolute periods when the user names a specific date or month, OR when expressing a comparison anchored to the past (e.g. "vs last year", "vs same period last year"). The relative keys do not include year-over-year shifts, so for `comparison_period` you usually want an absolute period.
- When the user asks "vs previous year" or "year-over-year": set `period` to the user's stated current window (relative) and set `comparison_period` to an absolute period whose date range is exactly one calendar year earlier than the primary period. Compute the absolute dates from today's date ($todayIso) - if the primary is "last 30 days" and today is 2026-05-07, the comparison_period should be `{type: "absolute", from: "2025-04-07", to: "2025-05-07"}`. Never set `period` and `comparison_period` to the same value - that produces a degenerate self-comparison.
PROMPT;
    }
}
