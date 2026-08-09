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
- [ ] After opening POS online, go offline/slow: product still sellable; photo still shows if warmed
- [ ] Food / Drinks chips filter correctly online **and** offline

## 4. Online POS sell

- [ ] Open check, add lines, change qty, clear, void
- [ ] Hold / save unpaid + resume from unpaid queue
- [ ] Pay cash (full)
- [ ] Pay M-Pesa / split tender (if enabled)
- [ ] Room charge to open folio (if `room_charge` on)
- [ ] Table required when `table_pos` on
- [ ] Check receipt prints (Print Agent or browser)

## 5. Offline cash bridge

- [ ] While online: catalog + check numbers warm (footer Sync / Pending sync)
- [ ] Offline: cash-only settle works; print works
- [ ] Offline: M-Pesa / room charge / hold blocked with clear message
- [ ] After reconnect: outbox syncs; check appears in Hospitality → Orders

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
