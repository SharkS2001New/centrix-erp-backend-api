# Centrix ERP API — Modular architecture

One Laravel API serves multiple frontends and deployment sizes. **What each customer gets** is controlled by `organizations.deployment_profile` and optional `enabled_modules` JSON overrides.

## Deployment profiles

| Profile | Typical buyer | POS | Mobile | Backend sales | HR | Accounting |
|---------|---------------|-----|--------|---------------|----|------------|
| `small_shop` | Single shop, no tills | Off | Off | On | Off | Off |
| `wholesale_retail` | Supermarket / wholesale + routes | On | On | On | On | On |
| `distribution` | Warehouse / distributor | Off | On | On | On | On |

Configure at onboarding:

```json
{
  "deployment_profile": "distribution",
  "enabled_modules": {
    "hr_payroll": true,
    "accounting": false
  },
  "module_settings": {
    "sales": { "auto_assign_truck": true, "require_weight_on_load": true },
    "inventory": { "default_distribution_sale_location": "store" }
  }
}
```

`enabled_modules` overrides profile defaults per key (see `config/erp.php`).

## Module map

| Module key | Responsibility |
|------------|----------------|
| `sales.pos` | Till, float sessions, cashier checkout |
| `sales.mobile` | Route/van orders, staged fulfillment |
| `sales.backend` | Admin/warehouse/small-shop order entry (no till) |
| `payments` | Cash, M-Pesa, partial pay, credit invoices |
| `inventory` | LPO, receipts, transfers, ledger, reservations |
| `accounting` | GL, journals (planned) |
| `hr_payroll` | Staff, payroll (planned) |
| `admin` | Users, roles, permissions |
| `customers_suppliers` | Debtors, route customers, suppliers |
| `reports` | SQL views + export APIs |

## Sales — one engine, three channels

All channels share:

- **`temporary_carts` + `cart_lines`** while building an order
- **`stock_reservations`** while cart/sale is open (config: `inventory.reserve_stock_on_cart`)
- **`sales` + `sale_items` + `sale_payments`** when committed
- **`inventory_transactions`** for stock balance (POS / mobile / backend)

| Channel | Client | Till required | Typical flow |
|---------|--------|---------------|--------------|
| `pos` | POS terminal | Yes | draft → completed (pay now) |
| `mobile` | Sales app | No | booked → pending → pay (partial ok) → processed → completed |
| `backend` | ERP web module | No | Same as mobile; credit orders allowed |

Walk-in customers: `customer_name_override` on `sales` only (no `customers` row).

## Payments

- **Pay now**: `sale_payments` rows; `sales.payment_status` = `paid`
- **Pay later / partial**: `payment_status` = `unpaid` | `partial` | `paid`; multiple `sale_payments`; credit path creates **`customer_invoices`**
- **Mobile / distribution stages**: driven by `sales.status` + `payment_status` (see `config/erp.php` → `workflows`)
- **M-Pesa (Daraja)**: per-organization under `module_settings.finance.mpesa` (Admin → Settings → Finance). Branches may override till/shortcode via `branches.settings.mpesa`. Register C2B/STK URLs on the Safaricom Daraja portal — the API stores and validates them but does not call Daraja register-URL. C2B callbacks are routed to the correct tenant by `BusinessShortCode`. Secrets are masked in API responses; `GET /erp/capabilities` never returns raw `consumer_secret` or `passkey`.
- **KRA PLU registration**: `POST /kra/register-products` uploads catalogue items to the on-prem device using the LightStores-style payload (`sn`, `is_test`, `plu_data`, single `sign_structure` object). Default device path `/api/register-plu` (configurable as `finance.kra_plu_register_path`). Sales still use `/api/complete-workflow`.

## Inventory

1. **LPO** (`lpo_mst` / `lpo_txn`) → order from supplier  
2. **Receive** (`stock_receipts`) → post `inventory_transactions` (`PURCHASE`)  
3. **Sell** → `POS_SALE` | `MOBILE_SALE` | `BACKEND_SALE`  
4. **Transfer** shop ↔ store → `TRANSFER` + `stock_movement_history`  
5. **Reserve** during active cart → `stock_reservations`  
6. **Cache** → `current_stock` (trigger by `stock_location`)

Distribution wholesale often sells from **store**; retail POS from **shop** (branch `module_settings`).

## Tenant catalog scope (organization vs branch)

Every organization is provisioned with a **Head Office** branch (`branch_code=HQ`). Operational data (stock, sales, carts) is always branch-aware.

**Products** support optional branch catalog scope:

| `branch_id` | Meaning |
|-------------|---------|
| `NULL` | Organization-wide — visible at every branch |
| set | Branch-only — visible only at that branch |

When an organization has **only one active branch**, the API treats the catalog as single-branch: new products are always organization-wide and scope pickers stay hidden in the UI.

When **multiple branches** exist, org-wide admins can create organization-wide or branch-scoped products. Branch-limited users only see org-wide products plus their branch’s scoped products, and can only create branch-scoped products for their branch.

`GET /erp/capabilities` includes `catalog.multi_branch`, `catalog.head_office_branch_id`, and related metadata for the frontend.

## Fulfillment (distribution / mobile)

` sales.fulfillment_meta` JSON — vehicle, driver, weights, loading sheet.  
`module_settings.sales.auto_assign_truck` / `require_weight_on_load` per org.

## API gating

```php
Route::middleware(['auth:sanctum', 'erp.module:sales.pos'])->group(function () {
    // till routes
});
```

Client bootstrap:

```
GET /api/v1/erp/capabilities
```

Returns enabled modules, channels, workflows, and settings for the logged-in user's organization.

## Implementation status

| Layer | Status |
|-------|--------|
| Schema v3.1 (56 tables, 30 report views) | Done |
| CRUD + operations API | Done |
| RBAC (`erp.permission`) | Done |
| Form requests (cart, checkout, stock) | Done |
| KRA pending receipt on checkout | Done |
| Auto sales journal (accounting) | Done |
| Stock take complete → ledger | Done |
| Postman collection `composer postman` | Done (all routes + Sanctum login script) |
| Feature tests (cart/checkout/stock, KRA/journal) | Done (`php artisan test`) |

See [`API_MODULES.md`](API_MODULES.md) for endpoint list.

## Still to harden before production frontends

Complete these before POS, mobile, or ERP web clients go live:

| Area | Action |
|------|--------|
| **Auth & RBAC** | Seed non-admin roles per branch; verify `erp.permission` on every ops route; add policies for row-level branch scope. |
| **Validation** | Extend Form Requests to remaining ops controllers (transfers, LPO receive, payroll, journals). |
| **Integrations** | Replace KRA `pending` stub with real device/API; configure `system_settings` URLs and secrets per env. |
| **Accounting** | Purchase receipts → auto journal; void/reversal entries; period close. |
| **Observability** | Structured audit on checkout/stock; health checks for MySQL. Rate limits, CORS lockdown, Sanctum token expiry, M-Pesa IP allowlist, and security headers are implemented — see `config/security.php`. |
| **API contract** | Extend Postman examples / published collection when routes change (`composer postman`). |
| **Testing** | Expand PHPUnit beyond cart/checkout (workflows, credit sales, stock-take, RBAC 403 cases). |
| **Ops** | Backups (`erp:database-backup` + cron), migration runbook, `.env` secrets; set `CORS_ALLOWED_ORIGINS` and `MPESA_CALLBACK_IP_CHECK` per env. |

Frontends should bootstrap from `GET /api/v1/erp/capabilities` and treat module flags as authoritative (do not hard-code feature visibility).

After deploying permission registry changes, run `php artisan erp:permissions-sync` (optionally `--grant-admin`), then re-save custom roles in **Admin → Roles & permissions** so new codes (e.g. `purchasing.supplier_payments.view`, `admin.payment_methods.view`) appear in the matrix.

## Email & notifications

- **SMTP transport** — per organization under **Admin → Settings → Notifications** (`smtp_enabled`, host, port, username, password, encryption), stored in `module_settings.notifications`. When organization SMTP is off, the API falls back to server `MAIL_*` environment variables.
- **From identity** — per organization (`email_from_name`, `email_from_address`); falls back to company profile (`org_name`, `org_email`).
- **Channels** — SMS (Africa's Talking) and email are independent. Event toggles (`notify_on_order_placed`, `notify_on_dispatch`, etc.) auto-send through every enabled channel that has a reachable customer contact (phone and/or email on the customer record).
- **Outbound mail** — `OrganizationMailSender` builds a dynamic SMTP mailer per org when configured; `CustomerNotificationDispatcher` fans out to SMS and email.

## Where the logic lives

Business logic is in **operations controllers** under `app/Http/Controllers/Api/V1/Operations/`:

| Controller | Routes (examples) |
|------------|-------------------|
| `CartOperationsController` | `POST /sales/carts`, lines, clear |
| `CheckoutController` | `POST /sales/carts/{id}/checkout` |
| `OrderWorkflowController` | `POST /sales/orders/{id}/transition` |
| `StockOperationsController` | availability, adjust |
| `StockTransferController`, `LpoReceiveController`, … | inventory ops |

Shared stock/pricing helpers used by several controllers:

- `Operations/Concerns/HandlesInventory.php`
- `Operations/Concerns/HandlesPricing.php`

Module gating stays in `app/Services/Erp/` (`ErpContext`, `CapabilityGate`) for middleware and `GET /erp/capabilities`. Controllers call `$this->erp->gateForUser($user)` before channel-specific work.
