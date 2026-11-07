# Centrix ERP Backend API — Recent Changes

Branch: `main` · Working tree: clean · Generated from `git log` on 2026-11-07.

Window covered: **2026-10-28 → 2026-11-07** (last ~40 commits).

---

## 1. Performance (Nov 7)

High-impact hot-path fixes landed in a single burst after the Symfony CVE
patch unblocked CI.

| Commit | Area | Change | Measured impact |
|---|---|---|---|
| _(uncommitted)_ | till reports | New migration `2026_11_07_000003_add_sale_payments_float_session_covering_index.php` adds covering composite index `idx_sp_float_sale_amount (float_session_id, sale_id, amount)` on `sale_payments`. Serves `TillReportMetrics::applyDebtorCollectionFilter` (`SUM(sp.amount) … WHERE sp.float_session_id = ? …`) fired by every x-report poll. Prod EXPLAIN on 2026-11-07 showed `sp` type `ALL` — the single-column `float_session_id` index from `2026_06_20_000001` was missing on prod despite the migration having run (batch 4). Covering composite answers the SUM straight from the index. Applied by hand on prod ahead of this migration landing; migration is idempotent so `migrate --force` is a no-op there. | `float_session_id=95` (15 matching rows of 43,682): type `ALL` → `ref`, rows examined 43,511 → 15, Extra `Using where` → `Using index`, warm query **10ms → 0.27ms** (~37×). Also drops out of the slow log entirely (now under the 0.5s `long_query_time`; only appeared because `log_queries_not_using_indexes` was on). |
| _(uncommitted)_ | migrations hygiene | Hardened `2026_06_20_000001_sale_payments_float_session.php`: the original wrapped both the column add and the index create inside one `Schema::hasColumn` gate, so if the column pre-existed via another path the `$table->index('float_session_id')` call was silently skipped — the exact failure mode observed on prod (migration recorded in batch 4, but `SHOW INDEX FROM sale_payments` had no `float_session_id` entry). Split into two independent idempotent guards (one for the column, one for the narrow index via an `indexExists()` helper matching `2026_11_07_000002`'s pattern), and reordered `down()` to drop the index before the column. Prod isn't re-run (Laravel only applies each migration once) but the covering index from `000003` already supersedes the narrow one there; the fix matters for `migrate:fresh` on local / CI / new tenants, which now reproducibly get both indexes. | Correctness-only; no runtime timing delta. Prevents future drift of the same class on any env that runs `migrate:fresh`. |
| `f1ed374d` | background tasks | New migration `2026_11_07_000002_add_background_tasks_status_index.php` adds a **status-only index** on `background_tasks`. The existing `(organization_id, type, status)` composite could not serve `SELECT * … WHERE status IN ('pending','running')` because its leading column was unmatched. | Slow log `Rows_examined` 928 → ~0 per poll. |
| `9bd2d967` | sales / stock | New migration `2026_11_07_000001_add_sales_pending_stock_deduct_index.php` adds a **VIRTUAL INVISIBLE** generated column mapping `fulfillment_meta->pending_stock_deduct` (true / 1 / 'true') to TINYINT, plus composite index `(stock_balanced, pending_stock_deduct, deleted_at)`. `FinalizePendingSaleStockCommand` rewritten to query the indexed column. | Full-table scan of ~43k rows (~0.7s) → indexed lookup. `VIRTUAL` chosen to avoid the `ALGORITHM=COPY` rebuild `STORED` would force on MySQL 8.4. `INVISIBLE` keeps `Model::replicate()` and API serializers clean. |
| `9e6049b4` | reports | `ReportController::reportFromViewOverall` no longer calls Laravel's `->paginate()` (which runs its own `COUNT(*)` against the expensive view subquery). Replaced with a hand-built `LengthAwarePaginator` that reuses `summary.row_count` from `aggregateFilteredReportSummary`. | salesByProduct (30-day, 476 rows): 3 view executions → 2 (~-33% DB time). |
| `8aa9824e` | reports | `aggregateFilteredReportSummary` now skips the sample-row probe when `effectiveSumColumns` is passed explicitly (the view-overall path always does). Direct callers passing `null` keep the probe + `summableColumnsForView` discovery. | salesByProduct: 4 queries / ~8070ms → 3 queries / ~6000ms (-25%). |
| `82ab8e81` | permissions | `PermissionMatrixService::enabledPermissionIds`, `groupedForUi`, and `buildRegistryModuleGroup` switched from `self::ensure()` to `self::ensureCached()`. `UserController::permissions()` also moved to the cached path. | `/roles/permissions/matrix`: was running the 15-step heal cascade 3× per request (~15s / 5630 queries). |
| `ea29c5d3` | permissions | `RoleController::permissionMatrix` switched to `ensureCached()`. The cache is keyed by registry version, 7-day TTL, so a deploy that bumps the registry still runs `ensure()` exactly once on cold boot. | Cold `ensure()`: 5107ms / 1405 queries → `ensureCached()`: **6.5ms / 0 queries**. Fixes slow `/admin/roles` page. |

Provisioning service and artisan commands still call `self::ensure()` directly,
so they force-refresh on deploy / tenant provisioning.

---

## 2. Security (Nov 7)

- **`adcdf5c6` — Symfony CVE patch (disclosed 2026-11-04).**
  `composer audit` had flagged 10 advisories against `symfony/http-foundation`,
  `http-kernel`, `routing`, `serializer`, `filesystem`, which hard-fails the CI
  deploy workflow and was blocking every backend push. Patch-level bumps
  within existing major.minor pins (`composer update --with-dependencies`):

  - `symfony/filesystem` v8.1.0 → v8.1.11
  - `symfony/http-foundation` v7.4.20 → v7.4.23
  - `symfony/http-kernel` v7.4.20 → v7.4.23
  - `symfony/routing` v7.4.20 → v7.4.23
  - `symfony/serializer` v8.1.1 → v8.1.11

  `composer.lock` only; no behavior changes. `composer audit` now clean.

- **`f4868211` (Oct 28)** — earlier housekeeping bump of
  `symfony/mime` and `symfony/polyfill-mbstring`.

---

## 3. Infrastructure / Deploy (Nov 4–6)

- **`df26673f`** — `config/database.php` now enables PDO TLS when
  `MYSQL_ATTR_SSL_CA` is set, with a new sibling env knob
  `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` (defaults `true`; flip to `false` for
  self-signed server certs whose SAN does not cover the connection host).
  MySQL 8.4 enforces TLS for the `caching_sha2_password` handshake even when
  `require_secure_transport` is OFF, so without this PDO/mysqlnd fails with
  `SQLSTATE[HY000] [1045] Access denied` regardless of credentials.
  Verified against the Ansible-provisioned MySQL at `10.0.0.1`.
- **`9f34c1ae`** — CI dispatch step now uses `CENTRIXERPK3SETUP_DISPATCH_TOKEN`.
- **`b9a73607`** — Helm deploy is dispatched via `repository_dispatch` instead
  of being inlined.
- **`edf3c3db`** — Dockerfile and CI workflows tuned.
- **`23808c8e`** — Centrix version handling unified across the Docker
  workflow and API responses.

---

## 4. Database migrations landed since Oct 28

| Migration | Purpose |
|---|---|
| `2026_11_07_000002_add_background_tasks_status_index.php` | Status-only index on `background_tasks` for in-flight polling. |
| `2026_11_07_000001_add_sales_pending_stock_deduct_index.php` | `pending_stock_deduct` virtual column + composite index on `sales`. |
| `4b13da80` (Nov 4) | Drops the legacy unique index on `employee_leave_days` and ensures the `employee_id` index exists. |
| `f520d4d5` (Oct 29) | KRA agent command expiry index. |

---

## 5. M-Pesa / payments (Oct 30 – Nov 4)

Significant surface changes in the M-Pesa integration:

- **`62b28ee1`** — M-Pesa STK push settings + core functionality added.
- **`c830d8fb`** — C2B URL registration endpoint added.
- **`2d464fd5`** — C2B URL registration returns a detailed response and logs;
  new **STK query service**.
- **`953a3e0f`** — Reduced connection / timeout settings for the M-Pesa URL
  registration service.
- **`c4900ce3`** — STK error handling: detailed logging and response messages.
- **`94054fbf`** — Phone number validation now requires **Kenyan format**;
  friendlier error message.
- **`31484cd0`** — STK request **locking** to prevent duplicate prompts; better
  handling of pending requests.
- **`2a2a21bb`** — Test: manual payment is exposed in organization billing when
  a paybill is configured.

---

## 6. Billing / organizations

- **`5391febf`** — Subscription and contract handling improved across billing
  services and controllers.
- **`df40460b`** — Organizations now carry a **`registration_source`**; new
  **failed jobs endpoint** added.

---

## 7. KRA / mobile sales (Oct 28–29)

- **`c4351ec3`** — Organization-specific KRA **Comstore API key** handling,
  with tests updated.
- **`307d1b1e`** — Workflow PLU line handling for fractional quantities;
  product-name sanitisation.
- **`f520d4d5`** — Mobile sales route handling + KRA agent command expiry
  index.
- **`9ff6dc41`** — Mobile sales and product listing pagination that does **not**
  count total items (cheap "next page?" style).
- **`f50580cb`** — Version bump to `1.0.34`.

---

## 8. Marketing / blog / portfolio

- **`f232922f`** — Image generation functionality removed from blog and
  portfolio services; related routes and tests updated.
- **`395d5ee5`** — While it still existed: better error messages for image
  generation + test for the 404 response.
- Several `Add new product image for DEMO organization` commits
  (`21908a3b`, `ec3cde47`, `972b4c55`, `0cbb9838`, `fc384392`, `a592e639`,
  `e91490ec`, `0bd707d0`) — demo-data image drops, no code impact.

---

## Deploy / rollout checklist

1. Run migrations (`php artisan migrate`) — picks up the two Nov-7 index
   migrations and the Nov-4 `employee_leave_days` migration.
2. Confirm `MYSQL_ATTR_SSL_CA` (and optionally
   `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=false`) are set in the deployment env if
   the target MySQL is 8.4 with `caching_sha2_password`.
3. After deploy, the first `/roles/permissions/matrix` and
   `/admin/roles` hits will warm the permission-matrix cache (one-time
   `ensure()` run keyed by registry version).
4. `composer audit` should report **no advisories**; CI deploy will fail
   otherwise.
