# AI Reports — roadmap for additional report types

Status of coverage and a plan for the report types merchants commonly ask for
that aren't supported yet.

## Already supported

- **Primitives:** `top_n`, `breakdown`, `time_series`, `growth`, `low_stock`, `stock_vs_velocity`
- **Dimensions:** product, sku, category, brand, customer, store, order_status,
  payment_method, shipping_method, region, country, coupon_code
- **Metrics:** qty_sold, revenue, net_revenue, order_count, aov, margin,
  discount_total, tax_total, shipping_total

The last row of each (payment_method … coupon_code, and discount/tax/shipping
metrics) shipped as the "four easy wins". What follows is the backlog.

---

## 1. New vs returning customers  — HIGH value, MEDIUM effort

**Questions:** "revenue from repeat customers", "how many first-time buyers last
month", "new vs returning split", "repeat purchase rate".

**Data:** `sales_flat_order` grouped by `customer_id`; an order is "new" if it's
the customer's first (min(created_at) per customer), else "returning". Guest
orders (customer_id null) bucket as "guest".

**Design:** new primitive `customer_type_breakdown` (or a `customer_type`
pseudo-dimension on breakdown/top_n). Self-join / window: for each order, compare
created_at to the customer's first order date within scope. Returns
new/returning/guest with revenue + order_count + share.

**Risks:** "first order ever" vs "first order in period" — decide semantics
(recommend: first order ever, so a customer who first bought 2 years ago is
"returning" even if their only in-period order is their 3rd). Window functions
need MySQL 8 / MariaDB 10.2+ (fine on this stack) but keep portable per the
SQL rule — prefer a derived first-order table over vendor-specific syntax.

## 2. Customer lifetime / RFM  — HIGH value, MEDIUM effort

**Questions:** "top customers by lifetime value", "customers who haven't ordered
in 90 days", "average orders per customer", "average customer lifetime spend".

**Design:** extend the `customer` dimension with lifetime aggregates (ignore the
period for LTV, or offer an `all_time` period). New metrics: `lifetime_value`,
`orders_per_customer`, `days_since_last_order` (recency). A dedicated `rfm`
primitive (recency/frequency/monetary buckets) is the fuller build; LTV top_n is
the quick win.

**Risks:** LTV is period-independent — the period selector is misleading. Either
force all-time or clearly label "as of {to date}".

## 3. Refunds & returns  — MEDIUM value, MEDIUM effort

**Questions:** "refund rate", "most-returned products", "total refunded last
month", "return reasons".

**Data:** `sales_flat_creditmemo` + `sales_flat_creditmemo_item`. Return reason
is usually a custom attribute or comment — confirm where it lives before
promising "by reason".

**Design:** new primitive `returns` (or a `refunded` metric + a creditmemo-based
dimension). `refund_rate` = refunded amount / net sales over the period. Most-
returned products = top_n over creditmemo_item by qty.

**Risks:** refund reason data may not exist; scope to amounts/quantities first.

## 4. Dead stock / overstock  — HIGH value, MEDIUM effort

**Questions:** "products with stock but no sales in 90 days", "dead stock", "stock
value on hand", "overstocked items".

**Data:** `cataloginventory_stock_item` (qty) + sales over a lookback. Stock
value needs cost (`cost` attribute or `base_cost`).

**Design:** new primitive `dead_stock` — products with qty_on_hand > 0 and
zero/low sales over a lookback window; sortable by stock value (qty × cost).
Complements `low_stock` / `stock_vs_velocity` (the inverse end).

**Risks:** cost may be sparsely populated — fall back to price × qty for "value"
with a caveat, or exclude rows missing cost.

## 5. Time patterns (day-of-week / hour-of-day)  — MEDIUM value, LOW effort

**Questions:** "what day of week do we sell most", "busiest hour", "weekend vs
weekday".

**Design:** add `granularity` values `day_of_week` and `hour_of_day` to
`time_series` (GROUP BY DAYOFWEEK/HOUR of the tz-converted created_at), or a
`time_bucket` dimension on breakdown. Reuse the existing CONVERT_TZ handling.

**Risks:** small — just tz-correct bucketing.

## 6. Abandoned carts  — MEDIUM value, MEDIUM effort

**Questions:** "abandoned cart value last week", "abandonment rate", "what's
sitting in carts".

**Data:** `sales_flat_quote` where `is_active = 1` and not converted, older than
N hours. Recovery rate needs linking quotes to placed orders.

**Design:** new primitive `abandoned_carts`. Different table than the sales
primitives, so its own value/dimension set (cart value, item count, age).

**Risks:** quote table is large and noisy (every guest session); needs sensible
filters (has items, min value, age window).

## 7. Basket analysis  — MEDIUM value, HIGH effort

**Questions:** "average items per order", "average basket size", "frequently
bought together".

**Design:** `items_per_order` is a quick metric (SUM(qty)/COUNT(DISTINCT order)).
"Frequently bought together" is a real market-basket build (self-join order_item
pairs, support/confidence) — defer or treat as its own project.

## 8. Targets / budgets  — LOW-MEDIUM value, MEDIUM effort

**Questions:** "are we on track vs target", "% to monthly goal".

**Design:** needs somewhere to store targets (new table + admin UI), then a
primitive that compares actual vs target. Out of scope until there's demand;
`growth` (period-vs-period) covers most "are we up or down" needs today.

---

## Suggested sequencing

1. **Dead stock** + **time patterns** — high value, contained, reuse inventory /
   time_series plumbing.
2. **New vs returning** + **customer LTV** — the customer-analytics pair; biggest
   merchant pull, share the first-order/aggregate logic.
3. **Refunds & returns** — once creditmemo reason data is confirmed.
4. **Abandoned carts** — separate data source, do as its own primitive.
5. **Basket analysis / targets** — only if requested.

## Cross-cutting notes

- Every new dimension/metric must be added to BOTH `TopN` and `Breakdown` arg
  schemas (the prompt auto-dumps them, the validator enforces them) and described
  in `PromptBuilder` so the LLM routes to it.
- Order-header metrics (net_revenue, tax_total, shipping_total, aov) inflate when
  grouped by an item-level dimension because of the order_item fan-out — use the
  per-line column override pattern already in `TopN::buildSelect` for any new
  money metric.
- Keep SQL portable (query builder, no MySQL-only syntax) per the Mageaustralia
  module rule.
- Drilldown: order-level string dimensions drill by label (see
  `applyStringDimensionFilter`); new primitives should set `supportsDrilldown()`
  honestly so the UI doesn't show dead chevrons.
