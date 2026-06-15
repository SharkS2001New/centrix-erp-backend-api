# API modules & endpoints

Base URL: `/api/v1` — Bearer token (Sanctum) unless noted.

## Reports (`/reports/*`)

**Catalog:** `GET /reports/` — lists all report keys grouped by category (sales, inventory, finance, operations).

Shared query params (where columns exist): `branch_id`, `from_date`, `to_date`, `date_column`, `per_page` (max 200), plus report-specific filters below.

### Sales

| GET | Report |
|-----|--------|
| `/reports/sales-by-product` | Product sales (`sale_date`, `product_code`, `channel`) |
| `/reports/sales-by-user` | Cashier / salesperson |
| `/reports/sales-by-customer` | Customer purchase & AR summary |
| `/reports/sales-by-channel` | By channel + payment status |
| `/reports/daily-sales` | Day totals by branch/channel |
| `/reports/mobile-route-sales` | Route / mobile loading |
| `/reports/sales-pipeline` | Open orders not completed (`status`, `payment_status`) |
| `/reports/vat-collected` | VAT by day |
| `/reports/category-sales` | Revenue by category / sub-category |
| `/reports/discount-summary` | Discounts given |
| `/reports/payment-collection` | Collections by payment method |
| `/reports/credit-outstanding` | Unpaid / partial credit sales |
| `/reports/eod-cashier` | End of day cashier |
| `/reports/returns` | Customer returns (table) |

### Inventory & purchasing

| GET | Report |
|-----|--------|
| `/reports/stock-on-hand` | Levels + reorder alerts |
| `/reports/low-stock` | Products at/below reorder point |
| `/reports/stock-movement` | Ledger transactions |
| `/reports/stock-chain` | Receipt → sale chain |
| `/reports/stock-valuation` | Cost vs retail value |
| `/reports/stock-reservations` | Active cart reservations |
| `/reports/stock-receipts` | Purchase receipts |
| `/reports/stock-transfers` | Shop ↔ store transfers |
| `/reports/open-lpo` | LPO lines pending receive |
| `/reports/purchases-by-supplier` | LPO / supplier summary |
| `/reports/damages` | Damages & write-offs |
| `/reports/supplier-returns` | Returns to suppliers |
| `/reports/price-list` | Wholesale + retail tiers |

### Finance & AR

| GET | Report |
|-----|--------|
| `/reports/profit-loss` | P&L by day/branch |
| `/reports/ar-aging` | Debtor aging buckets |
| `/reports/top-debtors` | Customers with outstanding balance |
| `/reports/invoice-payments` | AR payment history |
| `/reports/expenses` | Expenses by group |
| `/reports/journal-register` | GL journal entries |
| `/reports/kra-receipts` | KRA fiscal receipt status |

### Operations

| GET | Report |
|-----|--------|
| `/reports/till-sessions` | Till float sessions |
| `/reports/payroll-summary` | Payroll runs |
| `/reports/audit-trail` | Audit log (`user_id`, `table_name`, `action`) |
| `/reports/customers/{num}/statement` | Customer statement |

## HR & Payroll (CRUD)

Requires `hr_payroll` module where gated.

| Resource | Path |
|----------|------|
| Departments | `/departments` |
| Positions | `/positions` |
| Employees | `/employees` |
| Payroll deduction types | `/payroll-deduction-types` |
| Employee deductions | `/employee-deductions` |
| Overtime | `/employee-overtime` |
| Cash advances | `/employee-cash-advances` |
| Attendance | `/employee-attendance` |
| Employee documents | `/employees/{id}/documents` (+ `GET …/documents/{id}/file`) |
| Employee bank accounts | `/employees/{id}/bank-accounts` |
| Emergency contacts | `/employees/{id}/emergency-contacts` |
| Next of kin | `/employees/{id}/next-of-kin` |
| Pay periods | `/pay-periods` |
| Payroll runs | `/payroll-runs` |
| Payroll lines | `/payroll-lines` |

| Operation | Path |
|-----------|------|
| Kenya statutory preview | `GET /payroll/calculate?gross_pay=&other_deductions=` |
| Process payroll run | `POST /payroll/runs/{runId}/process` (auto_calculate default) |
| Process all active staff | `POST /payroll/runs/{runId}/process-auto` |
| Employee payroll history | `GET /employees/{id}/payroll-lines` |

Kenya auto-calc (2026): PAYE bands, personal relief KES 2,400, NSSF Tier I/II (6% up to KES 108k), SHIF 2.75% (min KES 300), Housing Levy 1.5% employee + employer. Rates in `config/kenya_payroll.php`.

## Sales operations

See [`ARCHITECTURE.md`](ARCHITECTURE.md) for cart/checkout flows.

| Method | Path |
|--------|------|
| POST | `/sales/carts` |
| GET | `/sales/carts/{id}` |
| POST | `/sales/carts/{id}/lines` |
| DELETE | `/sales/carts/{id}/lines` |
| POST | `/sales/carts/{id}/checkout` |
| POST | `/sales/orders/{id}/transition` |
| POST | `/sales/{id}/payments` |

## POS till

Requires `sales.pos` module and `pos.till` permission (cashiers). Admin users bypass permission checks.

| Method | Path | Notes |
|--------|------|-------|
| POST | `/pos/sessions/open` | Open or resume session; body: `till_id`, `working_amount`, `payment_type` |
| POST | `/pos/sessions/{id}/add-float` | Add float entry; body: `new_float`, `payment_type` |
| POST | `/pos/sessions/{id}/cash-movement` | Safe drop / pay-in / pay-out; body: `type` (`drop`, `pay_in`, `pay_out`), `amount`, optional `reason` |
| POST | `/pos/sessions/{id}/suspend` | Pause shift (blocks checkout until resumed) |
| POST | `/pos/sessions/{id}/resume` | Resume a suspended session |
| POST | `/pos/sessions/{id}/handover` | Manager handover to another cashier; body: `to_cashier_id`, optional `notes` |
| POST | `/pos/sessions/{id}/close` | Close session; body: `closing_amount`, optional `expected_amount`, `notes`, `closing_denominations` |
| GET | `/pos/sessions/{id}/x-report` | Interim report (session stays open) |
| GET | `/pos/sessions/{id}/z-report` | End-of-day report after close |

**Expected cash** on X/Z reports: opening float + cash sales − cash movements out + movements in − session expenses (`expenses.float_session_id`).

**Settings** (organization `module_settings`): `sales.blind_till_close` hides expected cash in the close UI; `accounting.post_till_variance` posts cash over/short journals (accounts `1000` / `5100`) on close.

### Till CRUD (`/tills`, `/till-float-sessions`)

| Method | Path | Notes |
|--------|------|-------|
| GET/POST/PATCH/DELETE | `/tills` | Till setup (admin) |
| GET/PATCH | `/till-float-sessions/{id}` | Session history; `POST` store blocked — use `/pos/sessions/open`. Float correction via PATCH requires `sales.manage` or admin. |

Expenses linked to an open session (`float_session_id`) reduce expected cash on till reports.

## Payments & M-Pesa

Organization config: `GET/PATCH /erp/settings/finance` → `finance.mpesa` (consumer key/secret, paybill, till, passkey, Daraja callback URLs). Branch overrides: `branches.settings.mpesa` (till/shortcode only). Register URLs on the Safaricom Daraja portal; the API validates stored URLs but does not call Daraja register-URL.

| Method | Path | Notes |
|--------|------|-------|
| POST | `/sales/carts/{id}/payment/mpesa/stk-push` | STK push; uses org (+ branch) M-Pesa config |
| POST | `/payments/stk/callback` | Daraja STK callback (no auth) |
| POST | `/payments/c2b/validation` | Daraja C2B validation (no auth) |
| POST | `/payments/c2b/confirmation` | Daraja C2B confirmation (no auth); org resolved by `BusinessShortCode` |
| GET | `/sales/carts/{id}/payment/mpesa/incoming` | List unmatched C2B payments for cart checkout |

`GET /erp/capabilities` includes `module_settings.finance.mpesa` with secrets masked.

| Method | Path | Notes |
|--------|------|-------|
| POST | `/kra/register-products` | Upload product PLU to on-prem KRA device; body `{ product_codes: [] }` or `{ all: true }` |

## Inventory operations

| Method | Path |
|--------|------|
| GET | `/inventory/availability` |
| POST | `/inventory/adjust` |
| POST | `/inventory/transfer` |
| POST | `/inventory/receive` |
| POST | `/inventory/returns` |
| POST | `/inventory/stock-take/{sessionId}/complete` |

## Postman

```bash
composer postman
```

Import `postman/POS-ERP-API.postman_collection.json` and `postman/Local.postman_environment.json`. Run **Auth → POST auth/login** first.

## Permissions (RBAC)

Operations routes use `erp.permission:*` middleware. Admin users (`is_admin=1`) bypass checks.

## Stock model

- **Ledger**: `inventory_transactions` (signed qty, `stock_location` shop/store)
- **Cache**: `current_stock` per branch
- **Reservations**: `stock_reservations` during open carts
- **SQL views**: 30 reporting views in `database/sql/schema.sql`
