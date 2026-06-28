# UAT — Wholesale & retail (`wholesale_retail`)

**Typical buyer:** Full stack — POS, mobile field sales, warehouse, accounting, HR.

## Setup

- [ ] Tenant provisioned with profile **Wholesale & retail**
- [ ] Roles: **Branch Manager**, **Cashier**, **Warehouse Clerk**, **Mobile Sales Rep**, **Accountant**, **Payroll Clerk**, **Viewer**
- [ ] Routes and route customers configured
- [ ] Mobile rep user with **mobile** login channel
- [ ] Products with wholesale tiers / route pricing

## Daily workflow

### POS (retail counter)
- [ ] Till session → retail sale → EOD

### Mobile (field sales)
- [ ] Rep logs in on mobile app
- [ ] Create order for route customer
- [ ] Partial payment / credit order
- [ ] Order visible in backoffice sales list

### Backoffice
- [ ] Warehouse order entry with credit terms
- [ ] Order workflow: unpaid → paid → processed (if distribution off)
- [ ] LPO receive into **store** location
- [ ] Inter-branch transfer (if multi-branch)

### Accounting
- [ ] Sales journal auto-posted
- [ ] Supplier payment journal
- [ ] Fiscal period open; trial balance balances

### HR (if enabled)
- [ ] Employee record linked to user
- [ ] Attendance clock in/out
- [ ] Payroll run for one period

## Edge cases

- [ ] Mobile rep restricted to assigned route
- [ ] Credit limit blocks excessive credit sale
- [ ] SMS/email on order placed (if notifications on)

## Sign-off

| Role | Name | Date | Pass |
|------|------|------|------|
| Operations manager | | | |
| Mobile team lead | | | |
| Accountant | | | |
| Platform admin | | | |
