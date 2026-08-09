# Hotel & Bar (`hotel_bar`) — UAT go-live checklist

Run on a fresh or staging Hotel & Bar tenant before production cutover.

## 1. Provisioning

- [ ] Org registered with profile `hotel_bar`
- [ ] Hospitality module + Hotel POS / Hotel Backoffice workspaces visible
- [ ] Platform services enabled as needed (`floor_tables`, `table_pos`, `room_charge`, reservations, front desk, folios, …)

## 2. Outlets & cashiers (Bar vs Restaurant)

- [ ] **Main bar** outlet exists (`outlet_type = bar`)
- [ ] **Hotel / restaurant** outlet exists (`outlet_type = restaurant`)
- [ ] Bar cashier user assigned to **Bar** outlet (Users → Hotel & Bar outlet)
- [ ] Restaurant cashier user assigned to **Restaurant** outlet
- [ ] Bar cashier POS title shows **Bar POS** and only Bar-channel products
- [ ] Restaurant cashier POS title shows **Restaurant POS** and only Restaurant/Hotel products
- [ ] Product with only `sell_on_bar` does **not** appear for restaurant cashier (and reverse)

## 3. Catalogue & images

- [ ] New product with photo uploads in backoffice
- [ ] Photo appears on Hotel POS while online
- [ ] After opening POS online, go offline: till **locks** with “Please check your internet connection” (no selling)
- [ ] Food / Drinks chips filter correctly while online
- [ ] Catalog / check-number warm while online (footer Pending sync / Sync)

## 4. Online POS sell (local-first)

- [ ] Open check, add lines, change qty, clear, void
- [ ] Hold / save unpaid + resume from Held (top chips: Hold + Held)
- [ ] Pay cash / M-Pesa / split (full): receipt prints **immediately** via Centrix Print Agent, then check syncs in background
- [ ] Room charge to open folio still settles online first (then prints)
- [ ] Partial payment (if enabled) settles online first (then prints)
- [ ] Table required when `table_pos` on
- [ ] With Print Agent stopped: pay still saves locally / queues sync, but print error asks to start Agent + Reprint
- [ ] Reprint last receipt works via Print Agent

## 5. Offline lock (no offline sell)

- [ ] While online: catalog + check numbers warm (footer Sync / Pending sync)
- [ ] Offline: full-screen lock — **Please check your internet connection**; cannot open checks, add lines, or pay
- [ ] Offline: room charge / unpaid queue unavailable
- [ ] After reconnect: lock clears; any pending outbox syncs; checks appear in Hospitality → Orders
- [ ] Failed sync: footer shows failed count + Reprint for last failed receipt

## 6. Stock (if deduct on settle)

- [ ] Recipes configured for sold items
- [ ] Stock on hand sufficient
- [ ] Settle deducts; insufficient stock blocked when setting requires it

## 7. Backoffice

- [ ] Hospitality dashboard loads
- [ ] Orders list + check detail
- [ ] Outlets / floor tables
- [ ] Hospitality settings (stock, recipes, email reports)
- [ ] At least one F&B report loads

## 8. Hotel PMS (only if services enabled)

- [ ] Rooms / rate plans
- [ ] Reservation create → Front desk arrival check-in
- [ ] Reservations **Room rack** shows overlapping stays
- [ ] Front desk **Departures** lists reservation + walk-in check-outs due today
- [ ] Folio charges / payments (Full / Keypad from Admin → Payment methods)
- [ ] Folio **Print statement**
- [ ] Deposit refund on folio when a reservation deposit was applied
- [ ] Check-out clears folio
- [ ] Night audit preview/run (if enabled); scheduled auto-run at 00:30
- [ ] Housekeeping board + assignee (if enabled)
- [ ] Hotel POS prepaid room sale occupies room; does not conflict with open folio stays

## Sign-off

| Role | Name | Date | OK |
|------|------|------|----|
| Bar cashier | | | |
| Restaurant cashier | | | |
| Manager | | | |
| IT / implementer | | | |
