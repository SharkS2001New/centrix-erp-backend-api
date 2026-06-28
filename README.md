# Centrix ERP API (Laravel 11, v3)

Modular ERP API for **Centrix ERP** by Alpac Software Solutions — small shop, wholesale/retail, and distribution deployments. Enable or disable POS, mobile, backend sales, HR, accounting, etc. per organization.

- Architecture: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- Module config: [`config/erp.php`](config/erp.php)
- Schema: [`database/sql/schema.sql`](database/sql/schema.sql)

## Schema v3 highlights

- Single auth: `users.password` (no separate mobile password)
- `vats` table (was `vat_statuses`)
- `product_code` VARCHAR(200) — barcode and internal code unified
- `temporary_carts` + `cart_lines` (replaces `temp_order` + `mobile_order_items`)
- `customer_invoices` / `customer_invoice_payments` (was credit_invoices)
- `stock_receipts`, `supplier_returns`, `lpo_supplier_invoices`
- Shop/store stock: `stock_in_shop`, `stock_in_store`, `current_stock.shop_quantity` / `store_quantity`
- Inventory ledger + trigger sync per `stock_location` (shop/store)
- Walk-in customers only via `sales.customer_name_override`
- Integration URLs on `system_settings`, not `organizations`

## Frontend (Next.js)

Sibling project: [`../pos-erp-web`](../pos-erp-web) — `npm run dev` on port 3000, connects to this API.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate

# MySQL 8.0+
# mysql -uroot -e "CREATE DATABASE pos_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"

php artisan migrate:fresh --seed
php artisan serve
```

## Capabilities (per tenant)

After login:

```bash
curl http://localhost:8000/api/v1/erp/capabilities \
  -H "Authorization: Bearer <token>"
```

Returns `deployment_profile`, enabled `modules`, allowed `channels`, and `workflows`.

## Auth

```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"password"}'
```

## Main endpoints (Bearer token)

| Resource | Endpoint |
|----------|----------|
| Products | `/api/v1/products/{product_code}` |
| Customers | `/api/v1/customers/{customer_num}` |
| VAT rates | `/api/v1/vats` |
| Carts | `/api/v1/temporary-carts`, `/api/v1/cart-lines` |
| Stock (per branch) | `/api/v1/current-stock?filter[branch_id]=1` |
| Customer AR | `/api/v1/customer-invoices` |
| Stock receipts | `/api/v1/stock-receipts` |
| Supplier returns | `/api/v1/supplier-returns` |

Query: `?filter[col]=value`, `?q=search`, `?per_page=50`

## Reporting

**30 SQL views** + table-backed reports. List all: `GET /api/v1/reports/`. Examples: sales-by-product, low-stock, open-lpo, profit-loss, ar-aging, payroll-summary, audit-trail. See [`docs/API_MODULES.md`](docs/API_MODULES.md).

## Operations API

Business endpoints (cart, checkout, stock, reports, payroll, journals): see [`docs/API_MODULES.md`](docs/API_MODULES.md).

```bash
GET  /api/v1/erp/capabilities
POST /api/v1/sales/carts
POST /api/v1/sales/carts/{id}/checkout
GET  /api/v1/inventory/availability?product_code=&branch_id=
GET  /api/v1/reports/stock-on-hand
```

## Postman

Import into Postman:

- `postman/POS-ERP-API.postman_collection.json` — all `/api/v1` routes (388 requests)
- `postman/Local.postman_environment.json` — `baseUrl` + `token`

```bash
php artisan serve
# In Postman: Auth → POST auth/login (saves Sanctum token), then any endpoint
composer postman   # regenerate collection after route changes
```

See [`postman/README.md`](postman/README.md).

## Before production frontends

See **Still to harden before production frontends** in [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) (RBAC coverage, real KRA, broader tests, ops runbook).

After deploying permission registry changes:

```bash
php artisan erp:permissions-sync
php artisan erp:permissions-sync --grant-admin   # optional: grant all permissions to Administrator roles
```

Then re-save custom roles in **Admin → Roles & permissions** so new feature codes appear in the matrix.

Production role templates are synced via `php artisan erp:sync-role-templates` (runs automatically after `migrate` in Docker/Helm bootstrap). Recommended roles per profile are returned when registering an organization.

## Production safety

Destructive database commands are **blocked when `APP_ENV=production`** unless you explicitly set `DB_ALLOW_DESTRUCTIVE_COMMANDS=true` (emergency only):

- `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`

Use normal migrations on production:

```bash
php artisan migrate --pretend   # review first
php artisan migrate --force
```

Never run `migrate:fresh` or `db:wipe` against a live database.

## Automated backups

Daily compressed SQL backups via:

```bash
php artisan erp:database-backup
```

Configure in `.env`:

| Variable | Purpose |
|----------|---------|
| `BACKUP_ENABLED` | Turn scheduled backups on/off |
| `BACKUP_NOTIFY_EMAIL` | Email recipient for backup notifications |
| `BACKUP_RETENTION_DAYS` | Delete local backups older than this |
| `BACKUP_SCHEDULE_TIME` | Daily run time (24h clock, `APP_TIMEZONE` / Africa-Nairobi) |
| `MAIL_*` | SMTP used to send backup emails |

Backups are stored under `storage/app/private/backups/database/` by default (gzip SQL). Files larger than `BACKUP_ATTACH_MAX_BYTES` (default 10 MB) trigger an email notification with the path only — not an attachment.

## Shared storage (API + queue workers)

Background report exports and large fetch caches write files under `BACKGROUND_EXPORT_DIRECTORY` (default `storage/app/private/backups/exports/`). The API creates export jobs; a **queue worker** (`php artisan queue:work`) generates the files and the API serves them — both processes must see the **same filesystem**.

| Path (under `storage/app/`) | Purpose |
|-----------------------------|---------|
| `private/backups/database/` | MySQL backups (`BACKUP_DISK=local`, `BACKUP_PATH=backups/database`) |
| `private/backups/exports/` | Report exports + task row cache (`BACKGROUND_EXPORT_DIRECTORY`) |

**Single server / same container:** Run `queue:work` on the same host (or in the same container) as the API — no extra setup.

**Kubernetes / multiple pods:** Mount one **shared volume** (PVC) on both the API deployment and the queue-worker deployment at `storage/app/private` (or at least `storage/app/private/backups`). Use the **same `.env`** on both so `BACKGROUND_EXPORT_DIRECTORY` and backup paths match.

**Docker Compose:** If you add a separate `queue` service, mount the same host path or named volume to `storage/app/private` on both `app` and `queue`. Alternatively, run `php artisan queue:work` inside the existing `app` container.

Recommended queue worker flags for large exports:

```bash
php artisan queue:work --memory=512 --timeout=3600
```

Enable the scheduler on the server:

```bash
* * * * * cd /path/to/pos-erp-api && php artisan schedule:run >> /dev/null 2>&1
```

Verify scheduled tasks:

```bash
php artisan schedule:list
```
