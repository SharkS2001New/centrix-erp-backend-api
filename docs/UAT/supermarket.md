# UAT — Supermarket (`supermarket`)

**Typical buyer:** Retail supermarket with POS tills and backoffice (no mobile field sales).

## Setup

- [ ] Tenant provisioned with profile **Supermarket**
- [ ] Roles: **Branch Manager**, **Cashier**, **Warehouse Clerk**, **Viewer**
- [ ] Cashier user with login channel **POS**
- [ ] Payment methods configured (Cash, M-Pesa if used)
- [ ] Products with barcodes / retail pricing
- [ ] Opening stock in **shop** location

## Daily workflow

### POS
- [ ] Open till / float session (if required by settings)
- [ ] Scan or search product; checkout with cash
- [ ] Checkout with M-Pesa STK (if enabled)
- [ ] Hold order and recall
- [ ] End-of-day / till close report
- [ ] Cashier cannot access accounting or admin screens

### Backoffice
- [ ] Backend order for wholesale customer (if enabled)
- [ ] Customer return / credit note
- [ ] GRN increases shop stock
- [ ] Stock take session completed and variances posted

### Accounting (if enabled)
- [ ] Sales auto-journal visible in GL
- [ ] P&L report for current month

## Edge cases

- [ ] POS completes without till when float not required
- [ ] Retail vs wholesale price on same product
- [ ] KRA receipt on checkout (if device enabled)

## Sign-off

| Role | Name | Date | Pass |
|------|------|------|------|
| Store manager | | | |
| Head cashier | | | |
| Platform admin | | | |
