# UAT — Small shop (`small_shop`)

**Typical buyer:** Single outlet, backoffice sales only (no POS till, no mobile).

## Setup

- [ ] Tenant provisioned with profile **Small shop**
- [ ] Administrator can sign in with company code
- [ ] Roles created: **Branch Manager**, **Warehouse Clerk**, **Viewer**
- [ ] At least 5 products and 1 customer imported or created
- [ ] Opening stock posted (receipt or adjustment)

## Daily workflow

### Sales
- [ ] Create backend sales order with walk-in customer name
- [ ] Create order for registered customer with full payment
- [ ] Create credit order; verify `payment_status` and customer balance
- [ ] Record partial payment on open order
- [ ] Print invoice/receipt
- [ ] Cancel an unpaid order

### Inventory
- [ ] View current stock (shop location)
- [ ] Post stock receipt (GRN) from supplier
- [ ] Stock adjustment (damage/write-off)
- [ ] Low-stock report shows expected items

### Purchasing
- [ ] Create LPO, receive against LPO
- [ ] Supplier payment recorded

### Reports
- [ ] Daily sales report for today
- [ ] Stock on hand export

## Edge cases

- [ ] Insufficient stock blocked or warned per org setting
- [ ] Branch-scoped user sees only allowed branch data
- [ ] Non-admin (Viewer) cannot create orders

## Sign-off

| Role | Name | Date | Pass |
|------|------|------|------|
| Shop owner | | | |
| Platform admin | | | |
