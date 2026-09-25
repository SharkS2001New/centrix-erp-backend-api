<?php

/**
 * Curated platform training notes that teach Centrix product mechanics.
 * Installed via Platform → AI training → "Install foundation notes".
 * Topics already present are skipped (safe to re-run).
 */
return [
    [
        'topic' => 'How Centrix stores stock quantities (base units)',
        'content' => 'All stock quantities in Centrix are stored in the product UoM base (smallest) unit — e.g. kg, pcs, or litres. Display mixes full packs and remaining small units (example: "2 Bag, 40 kg"). Never invent kg vs bags; quote qty_label from tools or product details. Configure packs at /uoms and assign the UoM on each product.',
        'path' => '/uoms',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'What is a Unit of Measure (UoM)?',
        'content' => 'A UoM defines packaging hierarchy: full pack name (e.g. Bag), optional middle pack, and small/base label (e.g. kg). conversion_factor is how many base units equal one full pack (e.g. 1 Bag = 50 kg). Manage at /uoms. For a specific product, call get_product_details or open /products/{code}.',
        'path' => '/uoms',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Retail packaging vs UoM — what is the difference?',
        'content' => 'UoM controls how stock is counted and displayed. Retail packaging (Sell on retail + /retail-package-settings) controls POS retail vs wholesale entry and markup tiers. They are separate: a bag/kg UoM can still sell retail by kg with retail markups. Use get_product_details for both on one product.',
        'path' => '/retail-package-settings',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Where do I receive goods (GRN)?',
        'content' => 'Receive stock against an LPO at /inventory/receipts (GRN). Create LPOs at /lpo and manage suppliers at /suppliers. After receiving, stock updates shop/store quantities in base UoM units.',
        'path' => '/inventory/receipts',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Where is End of Day / cashier sales summary?',
        'content' => 'End of Day and related cashier summaries live under reports and sales EOD screens (often /reports or Sales → End of Day depending on industry). Prefer find_screen with "end of day" or "EOD" and open the returned path. Payment mix and till variance also appear in till health tools.',
        'path' => '/reports',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'How much VAT do I have this month / for August?',
        'content' => 'Use get_vat_collected with relative_date=this_month or month=august year=2026 (or year_month=2026-08). Quote vat_collected_total and taxable_sales_gross in KES. Open the day/branch breakdown at /reports/vat-collected. This is VAT collected on Centrix sales (output VAT). Do not answer with LPO or find_screen alone.',
        'path' => '/reports/vat-collected',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'How should answers cite Centrix screens?',
        'content' => 'Always give a real Centrix path users can open (e.g. /suppliers, /lpo, /inventory/stock, /hr/attendance). Call find_screen when unsure. Only cite paths from tools, navigation, or trained notes — never invent menus.',
        'path' => '/dashboard',
        'workspace_id' => null,
    ],
    [
        'topic' => 'Hotel POS vs retail POS',
        'content' => 'Retail carts use /sales/pos or External POS /pos. Hotel & Hospitality checks use /hotel-bar-pos (not retail carts). Hotel lists: /hospitality/orders. Do not mix hotel check workflows with retail cart checkout.',
        'path' => '/hotel-bar-pos',
        'workspace_id' => 'hotel_bar_pos',
    ],
    [
        'topic' => 'Q: Is product stock in kg or bags?',
        'content' => 'A: Call get_product_details for that product. Read measurements.conversion_meaning and stock.*_qty_label. Example answer: "Stock is stored in kg (base). 1 Bag = 50 kg. On hand shows as 1 Bag, 40 kg." Do not guess from the product name alone.',
        'path' => '/products',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: How do I set retail packaging for a product?',
        'content' => 'A: Turn on Sell on retail on the product, then configure tiers at /retail-package-settings (min/max measures and markups). Confirm with get_product_details → retail_packaging. Path: /retail-package-settings.',
        'path' => '/retail-package-settings',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Custom reports',
        'content' => 'Users build saved reports in Report Builder at /reports/builder. After create_custom_report, give /reports/custom/{id}. Do not invent report ids.',
        'path' => '/reports/builder',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Profit, margins, and P&L in AI chat',
        'content' => 'Use get_profit_loss for gross/net profit, margins, COGS, and branch/product profitability. Link /reports/profit-loss for full detail. Never invent profit figures.',
        'path' => '/reports/profit-loss',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'AI insights from chat (anomaly, forecast, margins)',
        'content' => 'Call run_insight with insight_type such as anomaly_detection, forecast_light, margin_discount_watchdog, exception_radar, customer_360 (requires customer_num), procurement_companion, collections_playbook. Narrate the returned JSON slice.',
        'path' => '/reports',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Customer portfolio and churn',
        'content' => 'For lists of inactive or declining customers: get_customer_portfolio. For one customer deep-dive: run_insight customer_360 with customer_num. Collections: get_debtors_summary or run_insight collections_playbook.',
        'path' => '/customers',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Cash position and inventory valuation',
        'content' => 'Cash/treasury questions: get_cash_position (till + GL cash/bank + AR + AP estimate). Stock value: get_inventory_valuation. Accounting cash flow statement: /reports/cash-flow — different from till cash.',
        'path' => '/reports/cash-flow',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'What-if scenarios in AI chat',
        'content' => 'Use calculate_scenario with scenario_type (price_increase, sales_increase, supplier_cost_increase, discount_reduction) and percent_change. Always label results as illustrative estimates.',
        'path' => '/reports/profit-loss',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: What is bank reconciliation in Centrix?',
        'content' => 'A: Native GL matching of a bank/cash account to a bank statement. Import CSV (not Excel), match statement lines to posted book lines, Finish when |Difference| < 0.02. Path: /accounting/bank-reconciliation. Not M-Pesa/Equity recon.',
        'path' => '/accounting/bank-reconciliation',
        'workspace_id' => 'accounting',
    ],
    [
        'topic' => 'Q: What CSV format for bank statement import?',
        'content' => 'A: CSV/text only. Headers: date, description, reference, amount — or debit/credit (money_in/money_out). Example: date,description,reference,amount then 2026-06-01,Deposit,DEP-1,1500. Deposits positive; withdrawals negative. Dates YYYY-MM-DD preferred. Path: /accounting/bank-reconciliation.',
        'path' => '/accounting/bank-reconciliation',
        'workspace_id' => 'accounting',
    ],
    [
        'topic' => 'Q: How do I upload and reconcile a bank statement?',
        'content' => 'A: New reconciliation → bank account, statement date, ending balance → Bank statement tab → Import CSV → Reconcile tab (suggested/manual match, amounts within 0.02) → exclude noise → Add adjustment if needed → Finish now when Difference ≈ 0. Cleared items appear on /accounting/bank-register.',
        'path' => '/accounting/bank-reconciliation',
        'workspace_id' => 'accounting',
    ],
    [
        'topic' => 'Q: Why is bank reconciliation difference not zero?',
        'content' => 'A: Wrong ending balance typed, unmatched statement lines, uncleared book items, missing bank fee/interest journals, or bad matches. Match or exclude remaining lines; post missing journals; optional Add adjustment for immaterial gaps. Finish only when |Difference| < 0.02.',
        'path' => '/accounting/bank-reconciliation',
        'workspace_id' => 'accounting',
    ],
    [
        'topic' => 'Bank reconciliation vs other recon tools',
        'content' => 'Bank reconciliation = GL bank/cash vs bank statement CSV at /accounting/bank-reconciliation. M-Pesa, Equity, and Centrix Payments recon are separate payment-channel tools. Subledger recon compares AR/AP control accounts to operational balances — different again.',
        'path' => '/accounting/bank-reconciliation',
        'workspace_id' => 'accounting',
    ],
    [
        'topic' => 'Q: Can a Delivered order still be unpaid? What does Completed mean?',
        'content' => 'A: Yes — Delivered is fulfillment (goods handed over / trip POD). It may still be unpaid or partially paid. Completed means full payment is received (amount paid covers order total). Centrix blocks marking Completed while a balance remains; collect payment first (Sales → Unpaid / Partially paid, or Collect payment on the order). Paid is the payment stage; Delivered is not payment. Unpaid queues and Sales by User "Unpaid" use amount maths, so a Delivered order with nothing collected still counts as unpaid.',
        'path' => '/sales/orders',
        'workspace_id' => 'backoffice',
    ],
    [
        'topic' => 'Q: Which products have cost higher than selling price?',
        'content' => 'A: Call find_catalogue_exceptions with check=cost_above_selling (or check=all for a catalogue health overview). Quote totals and the product table (code, name, unit_price, last_cost_price, margin_pct). Link /products to fix prices. This is catalogue master data — not the same as margin_discount_watchdog (recent sale lines sold below cost). Other checks: zero_selling_price, zero_cost_price, thin_margin, missing_supplier, missing_reorder_point, below_reorder, missing_vat, missing_uom.',
        'path' => '/products',
        'workspace_id' => 'backoffice',
    ],
];
