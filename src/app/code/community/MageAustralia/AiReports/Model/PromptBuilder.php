<?php

declare(strict_types=1);

class MageAustralia_AiReports_Model_PromptBuilder
{
    public function __construct(
        private MageAustralia_AiReports_Model_PrimitiveRegistry $registry,
        private \DateTimeImmutable $today,
    ) {}

    /** @param int[] $userAccessibleStoreIds */
    public function build(array $userAccessibleStoreIds): string
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

        return <<<PROMPT
You are a reporting assistant for an e-commerce admin. Your job is to translate the user's natural-language question into a structured query plan that selects exactly one of the available primitives below.

Today's date is $todayIso. The user has access to stores with IDs: $stores. If the user does not specify a store, leave store_ids null (the system will scope to the user's allowed stores).

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

Prefer relative periods (e.g. "last_complete_month") over absolute dates when the user says "last month" or "this week". Use absolute periods only when the user names a specific date or month.
PROMPT;
    }
}
