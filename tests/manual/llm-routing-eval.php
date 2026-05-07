<?php

/**
 * MageAustralia_AiReports
 *
 * @copyright  Copyright (c) 2026 Mage Australia (https://mageaustralia.com.au)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

/**
 * LLM routing eval runner for MageAustralia_AiReports.
 *
 * Run from the Maho web root on staging:
 *   php app/code/community/MageAustralia/AiReports/tests/manual/llm-routing-eval.php
 *
 * Or via SSH from this repo after rsync:
 *   ssh -p 22582 web26@<staging-host> \
 *     'cd /var/www/.../web && php app/code/community/MageAustralia/AiReports/tests/manual/llm-routing-eval.php'
 *
 * Verifies that the LLM correctly routes natural-language questions to the right primitive
 * with sensible arg values. Expected pass rate: 18+/25 (72%) on the default model. If you
 * see <70%, the system prompt likely needs a tweak.
 *
 * Ambiguous prompts (marked with empty `expect` array) are scored as PASS as long as the
 * LLM returns valid JSON - no expectation on routing.
 */

require_once __DIR__ . '/../../../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../../../vendor/mahocommerce/maho/app/Mage.php';
Mage::app('admin');

// ---------------------------------------------------------------------------
// Test cases (25 total, covering all 6 primitives + edge cases)
// Each has:
//   q      - the natural-language question sent to the LLM
//   expect - {primitive?, metric?, dimension?, granularity?}
//            Empty array = ambiguous; any valid JSON response counts as PASS.
// ---------------------------------------------------------------------------
$cases = [
    // --- top_n: product / qty_sold ---
    ['q' => 'What were the top 20 selling products in April?',
     'expect' => ['primitive' => 'top_n', 'metric' => 'qty_sold', 'dimension' => 'product']],

    ['q' => 'Show me the best-selling 10 products this month',
     'expect' => ['primitive' => 'top_n', 'metric' => 'qty_sold', 'dimension' => 'product']],

    // --- top_n: product / revenue ---
    ['q' => 'Top 20 products by revenue last month',
     'expect' => ['primitive' => 'top_n', 'metric' => 'revenue', 'dimension' => 'product']],

    ['q' => 'Highest revenue products this quarter',
     'expect' => ['primitive' => 'top_n', 'metric' => 'revenue', 'dimension' => 'product']],

    // --- top_n: customer ---
    ['q' => 'Top 10 customers by revenue last 90 days',
     'expect' => ['primitive' => 'top_n', 'metric' => 'revenue', 'dimension' => 'customer']],

    ['q' => 'Best customers this year',
     'expect' => ['primitive' => 'top_n', 'dimension' => 'customer']],

    // --- top_n: sku / margin ---
    ['q' => 'What were the top SKUs by margin last quarter?',
     'expect' => ['primitive' => 'top_n', 'metric' => 'margin', 'dimension' => 'sku']],

    // --- top_n: store ---
    ['q' => 'Sales by store last month - which is biggest?',
     'expect' => ['primitive' => 'top_n', 'dimension' => 'store']],

    // --- breakdown: order_status ---
    // "Share of orders by status" is a distribution, not a ranking -> breakdown
    ['q' => 'Show the share of orders by status',
     'expect' => ['primitive' => 'breakdown', 'dimension' => 'order_status']],

    // --- growth ---
    ['q' => 'Which products had the biggest growth comparing the last 3 months to the previous 3 months?',
     'expect' => ['primitive' => 'growth', 'dimension' => 'product']],

    ['q' => 'Compare product performance Q1 vs Q2',
     'expect' => ['primitive' => 'growth']],

    ['q' => 'Biggest decliners in revenue this year vs last year',
     'expect' => ['primitive' => 'growth']],

    // --- time_series ---
    ['q' => 'Show daily revenue for the last 30 days',
     'expect' => ['primitive' => 'time_series', 'metric' => 'revenue', 'granularity' => 'day']],

    ['q' => 'Weekly orders for this quarter',
     'expect' => ['primitive' => 'time_series', 'metric' => 'order_count', 'granularity' => 'week']],

    ['q' => 'Monthly revenue trend over the last 12 months',
     'expect' => ['primitive' => 'time_series', 'metric' => 'revenue', 'granularity' => 'month']],

    ['q' => 'Daily order count for last 7 days',
     'expect' => ['primitive' => 'time_series', 'metric' => 'order_count', 'granularity' => 'day']],

    // --- breakdown ---
    ['q' => 'Break down revenue by store for last month',
     'expect' => ['primitive' => 'breakdown', 'dimension' => 'store']],

    ['q' => 'Revenue distribution across stores this year',
     'expect' => ['primitive' => 'breakdown', 'dimension' => 'store']],

    // --- stock_vs_velocity ---
    ['q' => 'For our top 50 sellers over the last 90 days, show stock on hand and days of cover.',
     'expect' => ['primitive' => 'stock_vs_velocity']],

    ['q' => 'Stock health for our best 20 sellers this year',
     'expect' => ['primitive' => 'stock_vs_velocity']],

    // --- low_stock ---
    ['q' => 'Which products have less than 14 days of stock cover based on the last 30 days of sales?',
     'expect' => ['primitive' => 'low_stock']],

    ['q' => 'What products are running low on stock?',
     'expect' => ['primitive' => 'low_stock']],

    ['q' => 'Show items with under 7 days of cover',
     'expect' => ['primitive' => 'low_stock']],

    // --- edge cases: ambiguous prompts ---
    // Empty expect = any valid-JSON response is a PASS.
    ['q' => 'How is the business doing this month?',
     'expect' => []],

    ['q' => 'Tell me about our top sellers',
     'expect' => ['primitive' => 'top_n']],
];

// ---------------------------------------------------------------------------
// Bootstrap objects
// ---------------------------------------------------------------------------

/** @var MageAustralia_AiReports_Helper_Data $helper */
$helper   = Mage::helper('aireports');
$registry = $helper->getRegistry();
$builder  = new MageAustralia_AiReports_Model_PromptBuilder($registry, new DateTimeImmutable('today'));
$validator = new MageAustralia_AiReports_Model_QueryPlanValidator($registry);

// Store IDs - use all stores for the eval; the system prompt lists them for context.
$allStoreIds = array_map(
    fn ($s) => (int) $s->getId(),
    array_values(Mage::app()->getStores(false))
) ?: [1];

$systemPrompt = $builder->build($allStoreIds);

// ---------------------------------------------------------------------------
// JSON extraction helper (mirrors AiReportsController::_extractFirstJsonObject)
// ---------------------------------------------------------------------------
function extractFirstJsonObject(string $raw): ?array
{
    $stripped = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($raw));
    $decoded  = json_decode($stripped, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    // Brace-balance extraction fallback.
    $start = strpos($stripped, '{');
    if ($start === false) {
        return null;
    }

    $depth = 0; $inStr = false; $esc = false;
    $len   = strlen($stripped);
    for ($j = $start; $j < $len; $j++) {
        $c = $stripped[$j];
        if ($inStr) {
            if ($esc) {
                $esc = false;
            } elseif ($c === '\\') {
                $esc = true;
            } elseif ($c === '"') {
                $inStr = false;
            }
            continue;
        }
        if ($c === '"') {
            $inStr = true;
        } elseif ($c === '{') {
            $depth++;
        } elseif ($c === '}') {
            $depth--;
            if ($depth === 0) {
                return json_decode(substr($stripped, $start, $j - $start + 1), true) ?: null;
            }
        }
    }

    return null;
}

// ---------------------------------------------------------------------------
// Run eval loop
// ---------------------------------------------------------------------------
$pass = 0;
$fail = 0;
$total = count($cases);

echo str_repeat('-', 72) . "\n";
echo "MageAustralia_AiReports - LLM Routing Eval\n";
echo 'Cases: ' . $total . "  |  Target: 18+ / 25 (72%)\n";
echo str_repeat('-', 72) . "\n\n";

foreach ($cases as $i => $case) {
    $q        = $case['q'];
    $expected = $case['expect'];
    $idx      = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);

    try {
        $raw = Mage::helper('ai')->invoke(
            userMessage: $q,
            systemPrompt: $systemPrompt,
            options: ['temperature' => 0.2],
            consumer: 'aireports_eval',
        );

        $plan = extractFirstJsonObject($raw);

        if (!is_array($plan)) {
            throw new RuntimeException('Non-JSON output: ' . substr($raw, 0, 120));
        }

        // Schema-validate the plan against the declared primitive (if present).
        if (isset($plan['primitive'])) {
            try {
                $validator->validate($plan, $allStoreIds);
            } catch (InvalidArgumentException $ve) {
                throw new RuntimeException('Validator rejected plan: ' . $ve->getMessage());
            }
        }

        // --- Scoring ---
        // Ambiguous case: empty expect array -> PASS if we got valid JSON.
        if ($expected === []) {
            echo sprintf("PASS [%s] %s\n  (ambiguous - accepted any valid JSON)\n", $idx, $q);
            $pass++;
            continue;
        }

        $matches = true;
        $reasons = [];

        if (isset($expected['primitive'])
            && ($plan['primitive'] ?? null) !== $expected['primitive']
        ) {
            $matches = false;
            $reasons[] = 'primitive=' . ($plan['primitive'] ?? '?')
                       . ' (expected ' . $expected['primitive'] . ')';
        }

        foreach (['metric', 'dimension', 'granularity'] as $key) {
            if (!isset($expected[$key])) {
                continue;
            }
            $got = $plan['args'][$key] ?? null;
            if ($got !== $expected[$key]) {
                $matches = false;
                $reasons[] = $key . '=' . ($got ?? 'null')
                           . ' (expected ' . $expected[$key] . ')';
            }
        }

        if ($matches) {
            echo sprintf("PASS [%s] %s\n", $idx, $q);
            $pass++;
        } else {
            echo sprintf("FAIL [%s] %s\n  - %s\n", $idx, $q, implode(', ', $reasons));
            $fail++;
        }
    } catch (Throwable $e) {
        echo sprintf("ERR  [%s] %s\n  - %s\n", $idx, $q, $e->getMessage());
        $fail++;
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n" . str_repeat('-', 72) . "\n";
$pct = $total > 0 ? round($pass / $total * 100) : 0;
echo sprintf(
    "Pass: %d / %d  (%d%%)   Fail/Err: %d\n",
    $pass,
    $total,
    $pct,
    $fail
);
if ($pct >= 72) {
    echo "Result: PASS (>= 72% threshold met)\n";
} else {
    echo "Result: FAIL (< 72% - review system prompt in PromptBuilder::build())\n";
}
echo str_repeat('-', 72) . "\n";

exit($fail === 0 ? 0 : 1);
