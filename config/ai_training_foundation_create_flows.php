<?php

/**
 * Conversational create / help-me-create Q&A (~100) across ERP modules.
 * Teaches Talk + Assistant: collect fields in chat first; never treat the
 * whole “can you help me create…” utterance as a supplier/product/customer name.
 * Merged into ai_training_foundation via array_merge.
 */
return [
    // --- Purchasing / LPO ---
    [
        'topic' => 'Q: Can you help me create an LPO?',
        'content' => 'A: Yes — start a create_lpo draft. Ask for the **supplier** and **line items** (product + qty, or a sales order to copy from). Do **not** treat this whole question as a supplier name. Never ask for confirm/save on this first turn. Offer **show form** only if they ask. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you help me create a purchase order?',
        'content' => 'A: Same as create LPO. Collect supplier + lines in chat first; confirm only when both are set. Never invent supplier names from the help question. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me raise an LPO',
        'content' => 'A: Start create_lpo. Ask which supplier and which products/quantities. Do not match the phrase “help me raise an LPO” as a supplier. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: I want to create an LPO',
        'content' => 'A: Start create_lpo conversationally — ask supplier + lines. No confirm yet. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Please create a PO for me',
        'content' => 'A: Treat as create_lpo. Ask supplier and products. “PO” / “purchase order” / “LPO” are the same create flow. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: How do I create an LPO with Centrix Talk?',
        'content' => 'A: Say you want an LPO, then name the supplier and products with quantities (e.g. “Supplier Bidco, Sugar x 20”). Reply **confirm** only after those are collected. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Create LPO from sales order SO-100',
        'content' => 'A: Start create_lpo with order_num/sale reference so lines copy from that sales order at last cost. Still need a supplier if not implied. Confirm when ready. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Make an LPO for Bidco with Kamande x 10',
        'content' => 'A: create_lpo with supplier Bidco and line Kamande qty 10. Resolve names from catalog; then ask confirm when ready. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Submit this LPO for approval',
        'content' => 'A: Use submit_lpo_for_approval with the LPO number after confirming which draft. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Approve LPO 4521',
        'content' => 'A: approve_lpo for that number after user confirms. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Mark LPO sent to supplier',
        'content' => 'A: mark_lpo_sent for the given LPO number after confirm. Offer PDF link. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Receive goods on this LPO',
        'content' => 'A: receive_lpo_goods (receive_all by default) after confirm. Path: /lpo or /inventory/receipts.',
        'path' => '/inventory/receipts',
        'workspace_id' => 'backoffice',
    ],

    // --- Products / catalog ---
    [
        'topic' => 'Q: Can you help me create a product?',
        'content' => 'A: Start create_product. Ask product name and key fields (UoM, VAT, selling/cost if known). Do not treat the whole help question as the product name. Confirm only when required fields are set. Path: /products.',
        'path' => '/products',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me add a new product',
        'content' => 'A: create_product — collect name first, then prices/UoM/VAT. Never invent SKUs; product_code can auto-generate. Path: /products.',
        'path' => '/products',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: I want to create a product called Cooking Oil 20L',
        'content' => 'A: create_product with product_name Cooking Oil 20L; ask remaining required fields then confirm. Path: /products.',
        'path' => '/products',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Change the selling price of Sugar to 250',
        'content' => 'A: update_product — resolve product Sugar, set unit_price 250 KES, confirm before save. Path: /products.',
        'path' => '/products',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you update product cost for Bidco Oil?',
        'content' => 'A: update_product last_cost_price after resolving the product by name. Confirm before save. Path: /products.',
        'path' => '/products',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Which products have cost higher than selling?',
        'content' => 'A: Call find_catalogue_exceptions check=cost_above_selling; quote the table. Path: /products.',
        'path' => '/products',
        'workspace_id' => 'backoffice',
    ],

    // --- Suppliers ---
    [
        'topic' => 'Q: Can you help me create a supplier?',
        'content' => 'A: Start create_supplier. Ask supplier name and contacts. Do not treat “help me create a supplier” as the supplier_name. Confirm when ready. Path: /suppliers.',
        'path' => '/suppliers',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Add a new supplier called Nairobi Hardware',
        'content' => 'A: create_supplier with supplier_name Nairobi Hardware; ask phone/email if useful, then confirm. Path: /suppliers.',
        'path' => '/suppliers',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: How do I pay a supplier?',
        'content' => 'A: Open /suppliers/payments or guide record supplier payment against outstanding LPOs. Do not invent balances. Path: /suppliers/payments.',
        'path' => '/suppliers/payments',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: What did we buy from Bidco this month?',
        'content' => 'A: Call get_supplier_statement for Bidco for the period; quote LPOs and products. Path: /suppliers.',
        'path' => '/suppliers',
        'workspace_id' => 'backoffice',
    ],

    // --- Customers / sales ---
    [
        'topic' => 'Q: Can you help me create a customer?',
        'content' => 'A: Start create_customer. Ask customer name and contacts. Never use the help phrase as customer_name. Confirm when ready. Path: /customers.',
        'path' => '/customers',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me add a walk-in customer',
        'content' => 'A: create_customer — collect name (and phone if available). Path: /customers.',
        'path' => '/customers',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you help me create a sales order?',
        'content' => 'A: Start create_sales_order. Ask customer + line items. Do not treat the help question as the customer name. Confirm when ready. Path: /sales/orders.',
        'path' => '/sales/orders',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me hold / park an order',
        'content' => 'A: create_held_order or guide hold on POS. Collect customer/lines if creating via chat. Path: /sales/pos.',
        'path' => '/sales/pos',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Record a customer payment',
        'content' => 'A: record_customer_payment — ask order/customer, amount, method. Confirm before posting. Path: /sales/shop-debtors/unpaid.',
        'path' => '/sales/shop-debtors/unpaid',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you help me collect payment from a debtor?',
        'content' => 'A: Guide Collect payment on /sales/shop-debtors/unpaid (or partial). Or start record_customer_payment in chat with order + amount. Path: /sales/shop-debtors/unpaid.',
        'path' => '/sales/shop-debtors/unpaid',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Who owes us money?',
        'content' => 'A: Call get_debtors_summary; quote names and amounts only. Path: /sales/shop-debtors/unpaid.',
        'path' => '/sales/shop-debtors/unpaid',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Where is POS / checkout?',
        'content' => 'A: Retail POS at /sales/pos or /pos. Hotel bar uses /hotel-bar-pos. Path: /sales/pos.',
        'path' => '/sales/pos',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: How do I cancel a sales order?',
        'content' => 'A: Open /sales/orders → Cancel order (or approval if required). How-to only — do not start create order. Path: /sales/orders.',
        'path' => '/sales/orders',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: How do I void a sale?',
        'content' => 'A: Use cancel/void from the order summary per org rules; stock restores on success. Path: /sales/orders.',
        'path' => '/sales/orders',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Where are returns / credit notes?',
        'content' => 'A: Open /sales/returns. Not the same as LPO returns. Path: /sales/returns.',
        'path' => '/sales/returns',
        'workspace_id' => 'backoffice',
    ],

    // --- Inventory ---
    [
        'topic' => 'Q: Can you help me receive stock?',
        'content' => 'A: Prefer receive against an LPO via receive_lpo_goods or /inventory/receipts GRN. Ask which LPO. Path: /inventory/receipts.',
        'path' => '/inventory/receipts',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: What is my stock for Sugar?',
        'content' => 'A: Call stock/product tools for Sugar by name; quote on-hand. Path: /inventory/stock.',
        'path' => '/inventory/stock',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Show low stock items',
        'content' => 'A: Use low-stock report tools or /reports/low-stock; never invent quantities. Path: /reports/low-stock.',
        'path' => '/reports/low-stock',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me do a stock adjustment',
        'content' => 'A: Guide Inventory → adjustments screen (find_screen stock adjustment). Confirm org permissions; do not invent qty. Path: /inventory.',
        'path' => '/inventory',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Where are categories and UoM?',
        'content' => 'A: Categories /categories; UoMs under inventory catalog settings. Products link to both. Path: /categories.',
        'path' => '/categories',
        'workspace_id' => 'backoffice',
    ],

    // --- Accounting ---
    [
        'topic' => 'Q: Can you help me with bank reconciliation?',
        'content' => 'A: Open /accounting/bank-reconciliation — match GL cash/bank to statement CSV. Not M-Pesa channel recon. Path: /accounting/bank-reconciliation.',
        'path' => '/accounting/bank-reconciliation',
        'workspace_id' => 'accounting',
    ],
    [
        'topic' => 'Q: Show accounts receivable',
        'content' => 'A: Accounting AR at /accounting/accounts-receivable; shop debtors queues are separate under Sales. Use get_debtors_summary for chat snapshot. Path: /accounting/accounts-receivable.',
        'path' => '/accounting/accounts-receivable',
        'workspace_id' => 'accounting',
    ],
    [
        'topic' => 'Q: Where is the chart of accounts?',
        'content' => 'A: Open accounting COA screen via find_screen “chart of accounts”. Path: /accounting.',
        'path' => '/accounting',
        'workspace_id' => 'accounting',
    ],
    [
        'topic' => 'Q: Help me post a journal entry',
        'content' => 'A: Guide Journals under Accounting; collect accounts/amounts then confirm on screen — do not invent GL codes. Path: /accounting.',
        'path' => '/accounting',
        'workspace_id' => 'accounting',
    ],
    [
        'topic' => 'Q: Supplier balance / AP overview',
        'content' => 'A: Call get_purchasing_overview or get_supplier_statement; link /suppliers. Path: /suppliers.',
        'path' => '/suppliers',
        'workspace_id' => 'accounting',
    ],

    // --- HR / payroll ---
    [
        'topic' => 'Q: Can you help me create an employee?',
        'content' => 'A: Start create_employee. Ask full name and required HR fields. Do not treat the help phrase as the employee name. Path: /hr.',
        'path' => '/hr',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me add a new staff member',
        'content' => 'A: create_employee — collect name, then role/branch as needed; confirm when ready. Path: /hr.',
        'path' => '/hr',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Where is payroll?',
        'content' => 'A: Open HR → Payroll via find_screen payroll. Path: /hr.',
        'path' => '/hr',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: How do I approve leave?',
        'content' => 'A: Open leave approvals in HR/Approvals inbox; do not invent leave balances. Path: /hr.',
        'path' => '/hr',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Attendance / clock devices',
        'content' => 'A: Guide attendance clock device settings under HR. Path: /hr.',
        'path' => '/hr',
        'workspace_id' => 'backoffice',
    ],

    // --- Fulfillment / logistics ---
    [
        'topic' => 'Q: Can you help me with picking?',
        'content' => 'A: Open fulfillment picking sheets / mobile picking. Use find_screen picking. Path: /fulfillment.',
        'path' => '/fulfillment',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Where are trip charts?',
        'content' => 'A: Fulfillment / sales trip charts screens — find_screen trip chart. Path: /fulfillment.',
        'path' => '/fulfillment',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Route orders today',
        'content' => 'A: Call get_route_orders for the date; quote results. Path: /fulfillment.',
        'path' => '/fulfillment',
        'workspace_id' => 'backoffice',
    ],

    // --- Hospitality ---
    [
        'topic' => 'Q: Can you help me open a hotel bar check?',
        'content' => 'A: Guide /hotel-bar-pos — not retail /sales/pos. Path: /hotel-bar-pos.',
        'path' => '/hotel-bar-pos',
        'workspace_id' => 'hospitality',
    ],
    [
        'topic' => 'Q: Where is hospitality POS?',
        'content' => 'A: Hotel & Hospitality check POS at /hotel-bar-pos. Path: /hotel-bar-pos.',
        'path' => '/hotel-bar-pos',
        'workspace_id' => 'hospitality',
    ],

    // --- Reports / admin / AI behavior ---
    [
        'topic' => 'Q: Can you help me create a report template?',
        'content' => 'A: create_report_template — ask report name and what it should show; confirm when ready. Path: /reports.',
        'path' => '/reports',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Show sales today',
        'content' => 'A: Call sales summary / insight tools for today; never invent totals. Path: /reports.',
        'path' => '/reports',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Where do I manage users and roles?',
        'content' => 'A: Administration → Users / Roles. Path: /admin or users/roles screens via find_screen. Path: /admin.',
        'path' => '/admin',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: What can Centrix Talk do?',
        'content' => 'A: Voice Q&A and guided creates (LPO, product, supplier, customer, orders, payments). Collect details then confirm — never claim saved until the system returns a real id.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: When should I say confirm?',
        'content' => 'A: Only after required fields for the pending create/update are collected. Never on the first “help me create…” turn.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: When should I say show form?',
        'content' => 'A: When the user wants an inline form instead of chatting fields. Do not show the form on the first help-me-create turn unless they ask.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Do not treat my question as a supplier name',
        'content' => 'A: Correct — phrases like “can you help me create an LPO” are intents, not supplier/product/customer names. Ask for the real name next.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],

    // --- More LPO phrasings (Talk / Kenyan English) ---
    [
        'topic' => 'Q: Naomba unisaidie kutengeneza LPO',
        'content' => 'A: User asked in Swahili to help create an LPO. Reply in English (or match their language if org prefers): start create_lpo, ask supplier + lines. Do not treat the Swahili sentence as a supplier name. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Draft purchase order please',
        'content' => 'A: create_lpo — ask supplier and products. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Start an LPO for me',
        'content' => 'A: create_lpo draft; ask supplier + line items. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: I need a new LPO',
        'content' => 'A: create_lpo — collect supplier and lines before confirm. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Could you create a purchase order?',
        'content' => 'A: Yes — create_lpo; ask supplier and items. Never use this sentence as supplier_name. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Would you help me raise a PO?',
        'content' => 'A: create_lpo flow; ask supplier + quantities. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Open LPO screen',
        'content' => 'A: Navigate / open_lpo to /lpo. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: List my LPOs awaiting approval',
        'content' => 'A: Call list_lpos with awaiting-approval status; quote numbers/suppliers by name. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: LPO details for 8891',
        'content' => 'A: Call get_lpo_details for that LPO number. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],

    // --- More create variants across modules ---
    [
        'topic' => 'Q: Can you help me create a supplier and then an LPO?',
        'content' => 'A: First create_supplier (name + contacts, confirm), then create_lpo with that supplier + lines. One write at a time. Path: /suppliers.',
        'path' => '/suppliers',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me create a product then add it to an LPO',
        'content' => 'A: create_product first; after saved, create_lpo including that product line. Path: /products.',
        'path' => '/products',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you help me create an invoice?',
        'content' => 'A: Sales invoices come from completing/checkout of orders — guide /sales/pos or /sales/orders. Do not invent invoice numbers. Path: /sales/orders.',
        'path' => '/sales/orders',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me create a quotation / proforma',
        'content' => 'A: Guide proforma/quotation from sales order flow when enabled. Path: /sales/orders.',
        'path' => '/sales/orders',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you help me create a role?',
        'content' => 'A: Guide Administration → Roles; do not invent permissions. Path: /admin.',
        'path' => '/admin',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me create a user',
        'content' => 'A: Guide Administration → Users; collect name/username/role on screen. Path: /admin.',
        'path' => '/admin',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you help me create a branch?',
        'content' => 'A: Guide org/branch admin settings if permitted; never invent branch ids. Path: /admin.',
        'path' => '/admin',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me create a category',
        'content' => 'A: Guide /categories to add a category, or collect name if a create action exists. Path: /categories.',
        'path' => '/categories',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you help me create a VAT rate?',
        'content' => 'A: Guide tax/VAT master under catalog/admin settings. Path: /admin.',
        'path' => '/admin',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me create a payment voucher',
        'content' => 'A: Guide supplier payments or accounting payment vouchers via find_screen. Path: /suppliers/payments.',
        'path' => '/suppliers/payments',
        'workspace_id' => 'accounting',
    ],
    [
        'topic' => 'Q: Can you help me create an expense?',
        'content' => 'A: Guide expenses/journals under Accounting; collect amounts/accounts on screen. Path: /accounting.',
        'path' => '/accounting',
        'workspace_id' => 'accounting',
    ],
    [
        'topic' => 'Q: Help me create a delivery note',
        'content' => 'A: Guide fulfillment/sales delivery documents via find_screen delivery note. Path: /fulfillment.',
        'path' => '/fulfillment',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you help me create a GRN?',
        'content' => 'A: Stock receipt GRN at /inventory/receipts — usually from an approved LPO. Path: /inventory/receipts.',
        'path' => '/inventory/receipts',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me create a goods return to supplier',
        'content' => 'A: Guide purchasing/inventory return-to-supplier flow via find_screen; not sales returns. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you help me create a credit note?',
        'content' => 'A: Sales credit notes/returns at /sales/returns. Path: /sales/returns.',
        'path' => '/sales/returns',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me create a stock transfer',
        'content' => 'A: Guide inventory transfer between locations/branches via find_screen. Path: /inventory.',
        'path' => '/inventory',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you help me create a price list?',
        'content' => 'A: Guide product pricing / price lists under catalog if enabled. Path: /products.',
        'path' => '/products',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me create a discount rule',
        'content' => 'A: Guide promotions/discount settings; do not invent approval rules. Path: /admin.',
        'path' => '/admin',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you help me create a till session?',
        'content' => 'A: Guide Till management /sales till screens for open/close float. Path: /sales.',
        'path' => '/sales',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me create an approval request',
        'content' => 'A: Approvals are created by business actions (discount, cancel, LPO submit). Open Approvals inbox to act on them. Path: /approvals.',
        'path' => '/approvals',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you help me create a shift roster?',
        'content' => 'A: Guide HR roster/shift screens via find_screen. Path: /hr.',
        'path' => '/hr',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me create a leave request',
        'content' => 'A: Guide HR leave request; do not invent balances. Path: /hr.',
        'path' => '/hr',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you help me create a room booking?',
        'content' => 'A: Guide hospitality reservations if module enabled. Path: hospitality workspace.',
        'path' => '/hotel-bar-pos',
        'workspace_id' => 'hospitality',
    ],
    [
        'topic' => 'Q: Help me create a kitchen order',
        'content' => 'A: Guide hotel/bar POS check flow for kitchen tickets. Path: /hotel-bar-pos.',
        'path' => '/hotel-bar-pos',
        'workspace_id' => 'hospitality',
    ],
    [
        'topic' => 'Q: Can you help me create a mobile sales order?',
        'content' => 'A: Guide mobile/route order screens or create_sales_order with route context. Path: /fulfillment.',
        'path' => '/fulfillment',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me create a picking list',
        'content' => 'A: Guide fulfillment picking list print/screens. Path: /fulfillment.',
        'path' => '/fulfillment',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you help me create a bank payment?',
        'content' => 'A: Guide supplier payment or accounting payment with bank method; confirm amounts from tools. Path: /suppliers/payments.',
        'path' => '/suppliers/payments',
        'workspace_id' => 'accounting',
    ],
    [
        'topic' => 'Q: Help me create an M-Pesa receipt recon',
        'content' => 'A: Guide M-Pesa/channel recon tools — separate from bank reconciliation CSV. Path: /accounting.',
        'path' => '/accounting',
        'workspace_id' => 'accounting',
    ],
    [
        'topic' => 'Q: Can you help me create a budget?',
        'content' => 'A: Guide accounting budgets if enabled; do not invent figures. Path: /accounting.',
        'path' => '/accounting',
        'workspace_id' => 'accounting',
    ],
    [
        'topic' => 'Q: Help me create a fixed asset',
        'content' => 'A: Guide fixed assets module via find_screen. Path: /accounting.',
        'path' => '/accounting',
        'workspace_id' => 'accounting',
    ],
    [
        'topic' => 'Q: Can you help me create a warehouse location?',
        'content' => 'A: Guide inventory locations/warehouses settings. Path: /inventory.',
        'path' => '/inventory',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me create a reorder point for Sugar',
        'content' => 'A: update_product / product edit for Sugar reorder point; confirm before save. Path: /products.',
        'path' => '/products',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you help me create a barcode for a product?',
        'content' => 'A: Set barcode on create_product or product edit; do not invent conflicting barcodes. Path: /products.',
        'path' => '/products',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me create a customer with credit limit',
        'content' => 'A: create_customer including credit limit if field exists; never invent utilization. Path: /customers.',
        'path' => '/customers',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you help me create a sales route?',
        'content' => 'A: Guide routes master under sales/fulfillment settings. Path: /fulfillment.',
        'path' => '/fulfillment',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me create a vehicle for trips',
        'content' => 'A: Guide fleet/vehicle master if enabled via find_screen. Path: /fulfillment.',
        'path' => '/fulfillment',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Can you help me create a tax invoice?',
        'content' => 'A: Tax invoices print from completed sales orders / eTIMS flows when enabled — open the order. Path: /sales/orders.',
        'path' => '/sales/orders',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Help me create an LPO PDF',
        'content' => 'A: After create_lpo succeeds, share document_links PDF/print. Until saved, collect supplier + lines then confirm. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Talk mode — create LPO without typing',
        'content' => 'A: In voice mode, ask aloud for supplier then products with quantities; say confirm when ready. Never claim the LPO exists before the system returns an LPO number.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: What if supplier name is not matched?',
        'content' => 'A: Ask for the exact supplier name, @mention, or **show form**. Never keep using the original help-me-create sentence as supplier_name.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Example good LPO reply after help request',
        'content' => 'A: “Sure — I can create an LPO. Which supplier should I use, and what products and quantities?” Then wait. Path: /lpo.',
        'path' => '/lpo',
        'workspace_id' => 'backoffice',
    ],
];
