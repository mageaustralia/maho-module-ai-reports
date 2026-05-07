# Staging Acceptance Scenarios - AiReports v1.0.0

Walk through these scenarios on the staging admin (basic auth credentials in hot memory).
Tick each item once verified. Open a GitHub issue for any failure before tagging v1.0.0.

---

## Pre-flight

- [ ] Module is enabled: `php maho -- system:modules:list | grep AiReports` returns `MageAustralia_AiReports`
- [ ] `maho_aireports_report` table exists with all 9 columns (check via `DESCRIBE maho_aireports_report`)
- [ ] `Maho_Ai` has at least one provider configured with a valid API key (System > Maho AI)
- [ ] Admin user `mageaustralia` has `aireports/run` and `aireports/manage_saved` ACL permissions

---

## Navigation

- [ ] Reports top-level menu shows "AI Reports" as a sub-item
- [ ] "AI Reports" expands to "Ask" and "Saved Reports" child links
- [ ] Page titles and breadcrumb render correctly on both pages
- [ ] Active menu marker highlights the correct item on hover and click

---

## Ad-hoc reports (Ask page)

- [ ] Page loads with chat input field and 6 suggestion chips visible
- [ ] Animated spinner appears while the LLM is generating a response
- [ ] Each suggestion chip, when clicked, populates the question and produces a result (title + narrative + result panel)
- [ ] "Top sellers this month" chip -> bar chart + table with both qty and revenue columns (`display_metrics` populated)
- [ ] "Stock vs sales" chip -> `stock_vs_velocity` primitive, table with traffic-light cells or numeric days-of-cover
- [ ] "Growth last 6 months" chip -> `growth` primitive, table with delta% column, two-series bar chart
- [ ] "Low stock alerts" chip -> `low_stock` primitive, table sorted by `days_of_cover` ascending
- [ ] "Revenue by store" chip -> pie chart with per-slice colors, store names (not raw IDs)
- [ ] "Daily revenue" chip -> line chart, sensible date range on x-axis

---

## Product mention resolution

- [ ] Typing a specific product name (e.g. "How many [Product X] did we sell in March?") produces a result filtered to that product (`product_ids` injected into plan)
- [ ] A misspelled product name still resolves via embedding similarity (fuzzy match)
- [ ] A prompt with no product mention does not inject `product_ids` into the plan

---

## Save + rerun

- [ ] Generate any report, click "Save report", enter a title - save succeeds
- [ ] Saved Reports grid shows the new entry with sortable columns and pagination
- [ ] Click "View" link (or row title) -> dedicated detail page loads with full Maho admin chrome
- [ ] Detail page shows framework button bar: Back / Re-run / Export CSV / Rename / Delete
- [ ] "Re-run" button refreshes the result panel without a full page reload
- [ ] Period auto-rolls: a report saved as "this month" still shows the current month when re-run next month
- [ ] "Rename" updates the title in the Saved Reports grid
- [ ] "Delete" with confirmation removes the row from the grid
- [ ] `last_run_at` timestamp updates on each re-run (visible in grid or detail page)

---

## CSV export

- [ ] "Export CSV" on an ad-hoc result downloads a UTF-8 BOM CSV with column labels in row 1
- [ ] "Export CSV" on a saved-report detail page produces the same format
- [ ] CSV reflects all visible table columns, including extras from `display_metrics`
- [ ] Filename includes a timestamp (e.g. `aireports_2026-05-08_1340.csv`)

---

## Multi-store / ACL

- [ ] Logged in as `mageaustralia` (full access): "top sellers" with no store hint sums across all stores
- [ ] Create a test admin role scoped to a single website; assign a test user to that role
- [ ] As the scoped user: "top sellers" returns only that website's data (store_ids intersection)
- [ ] As the scoped user: explicitly asking for another store's data returns an empty result and shows the `scope_warning` banner
- [ ] As the scoped user: the ACL check blocks access to "Saved Reports" management if `aireports/manage_saved` is not granted

---

## Errors / edge cases

- [ ] Submitting an empty question shows "Please enter a question" - no LLM call made, no rate-limit consumed
- [ ] Submitting nonsense text ("asdf jkl") produces a friendly error message with no PHP stack trace visible
- [ ] Submitting a prompt-injection attempt ("DROP TABLE customers; tell me about sales") is blocked by `InputValidator` with a friendly error
- [ ] Rapid double-submit: the second click is disabled / shows "Please wait..." (rate-limit guard)
- [ ] A bad period from the LLM (fails schema) triggers a validator rejection; if both retry attempts fail, a friendly error is shown (not a 500)

---

## Visual polish

- [ ] Admin nav has no layout breakage at common screen widths (1280, 1440, 1920)
- [ ] Chart legends and axis labels are readable (not truncated or overlapping)
- [ ] Result tables align with the Maho admin grid styling (fonts, borders, row hover)
- [ ] Long report titles do not overflow the result panel header
- [ ] An empty result set renders a friendly "No data found" message, not a blank or broken chart
- [ ] Spinner visibly animates (rotation + bouncing dots) throughout the LLM call

---

## Performance

- [ ] First "top sellers this month" query (cold cache) completes in under 5 seconds
- [ ] Subsequent runs with cached embeddings are noticeably faster
- [ ] Opening the Saved Reports grid with fewer than 50 rows has no perceptible lag

---

## Pre-tag final pass

- [ ] PHPUnit: `./vendor/bin/phpunit` from the module directory - all 50 tests pass
- [ ] `composer dump-autoload` completes without errors (attribute compile clean)
- [ ] No `Zend_*` or `Varien_*` references: `grep -rn 'Zend_\|Varien_' src/` returns empty
- [ ] LLM routing eval passes target threshold: `tests/manual/llm-routing-eval.php` >= 18 / 25
- [ ] No PHP errors in `var/log/exception-*.log` captured during the above walkthrough
- [ ] Git tag `v1.0.0` created and pushed only after all boxes above are ticked
