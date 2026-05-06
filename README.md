# MageAustralia_AiReports

Natural-language reporting for the Maho admin. Ask a question in plain English and get an
interactive table or chart built from live store data, with no SQL required.

## Features

- **Chat input** - type a question (e.g. "top 20 sellers last month") and the LLM generates
  a validated query plan, executes it, and renders the result.
- **Six built-in primitives** - top_n, growth, time_series, breakdown, stock_vs_velocity,
  low_stock. Each has a JSON Schema contract validated before execution.
- **Save and re-run** - save any ad-hoc report with a title, then run it again from the
  Saved Reports grid.
- **CSV export** - download the table view of any result as a UTF-8 CSV file (BOM included
  for Excel compatibility).
- **Multi-store ACL** - results are scoped to the stores accessible to the current admin
  user; cross-store requests produce a warning banner rather than silently over-reporting.
- **Customer PII masking** - if the current admin lacks the customer-management permission,
  customer-dimension labels are replaced with `[masked]`.
- **Rate limiting** - a 5-second per-user cooldown is enforced after each successful
  invocation (not before, so empty submits don't consume the window).
- **Retry on schema failure** - the LLM is called a second time with the validation error
  appended if the first response fails JSON decoding or schema validation.

## Requirements

- **Maho** ^26.3 (PHP 8.3+)
- **opis/json-schema** ^2.3 (pulled in by this package)
- **Maho_Ai** core module with at least one LLM provider configured in System > Maho AI

## Installation

```bash
composer require mageaustralia/maho-module-ai-reports
composer dump-autoload
```

Then run the setup scripts (creates `maho_ai_report` table):

```bash
php bin/maho setup:upgrade
```

Or apply `src/app/code/community/MageAustralia/AiReports/sql/aireports_setup/install-1.0.0.php`
manually if you are not using the Maho CLI.

## Configuration

No module-specific configuration panel is required. The module uses whichever LLM provider
and model are configured in **System > Configuration > Maho AI** under the `aireports`
consumer group.

Recommended settings for the LLM provider:

| Setting        | Suggested value                              |
|----------------|----------------------------------------------|
| Provider       | anthropic                                    |
| Model          | claude-sonnet-4-6 (or any capable model)     |
| Temperature    | 0.2 (set by the module automatically)        |

## Permissions

Two ACL resources are used:

| Resource                  | Grants                                              |
|---------------------------|-----------------------------------------------------|
| `aireports/run`           | Access the Ask page and run ad-hoc or saved reports |
| `aireports/manage_saved`  | Save, rename, and delete saved reports              |

Configure these in **System > Permissions > Roles**.

## Navigation

- **AI Reports > Ask** - ad-hoc question interface with six pre-built suggestion chips
- **AI Reports > Saved Reports** - grid of saved reports with Run, Export CSV, Rename,
  Delete actions

## Known limitations

- **Category and brand dimensions** - the `category` and `brand` dimension options in
  `top_n` and `growth` use a product-level fallback in v1. Full category-tree joins are
  planned for v1.1 once the brand attribute code is confirmed.
- **Single-currency CSV** - exported CSV values are raw numbers; currency formatting uses
  the store default and is not localised per-row.
- **No bulk export** - CSV exports one primitive result at a time; reports with chart +
  table blocks export only the table.

## Run unit tests

```bash
composer install
./vendor/bin/phpunit
```

## License

Open Software License 3.0 (OSL-3.0). See `LICENSE` for the full text.
