# UAT — Warehouse & distribution (`distribution`)

**Typical buyer:** Warehouse distributor with mobile route sales and dispatch logistics (no POS).

## Setup

- [ ] Tenant provisioned with profile **Distribution**
- [ ] Roles: **Branch Manager**, **Warehouse Clerk**, **Dispatch Coordinator**, **Mobile Sales Rep**, **Driver**, **Accountant**, **Viewer**
- [ ] Routes, drivers, vehicles, route schedules created
- [ ] Customers assigned to routes
- [ ] Stock in **store** location
- [ ] Distribution settings: auto-create trips, assign on **Processed**

## Daily workflow

### Order → dispatch
- [ ] Mobile or backend order for route customer (route inherited from customer)
- [ ] Advance order to **Processed**
- [ ] Order auto-assigned to today's trip for that route
- [ ] Loading list shows aggregated products
- [ ] Lock loading list (prepared by / checked by)
- [ ] Start trip → status **in transit**

### Delivery
- [ ] Mark order **Delivered** (mobile or backoffice)
- [ ] Capture POD if required
- [ ] COD: record payment on delivery
- [ ] Complete trip; cash settlement if enabled

### Dispatch board
- [ ] Fulfillment → Dispatch shows ready orders
- [ ] Manual assign order to different trip works
- [ ] Trip reconciliation shows blockers before complete

### Reports
- [ ] Dispatch trips report
- [ ] Mobile route sales
- [ ] Trip cash settlement (if COD enabled)

## Edge cases

- [ ] Order without route does not appear on loading list
- [ ] Vehicle capacity warning when enforced
- [ ] Stock deduct on trip load/depart per org setting
- [ ] Customer dispatch SMS (if enabled)

## Sign-off

| Role | Name | Date | Pass |
|------|------|------|------|
| Warehouse manager | | | |
| Dispatch coordinator | | | |
| Mobile rep / driver | | | |
| Platform admin | | | |
