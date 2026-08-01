<?php

namespace App\Services\Ai;

/**
 * Registry of Centrix AI insight types (digests + on-demand analyses).
 */
class AiInsightCatalog
{
    /**
     * @return list<string>
     */
    public static function allTypes(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * Scheduled morning digests (enabled + schedule_time in settings).
     *
     * @return list<string>
     */
    public static function scheduledTypes(): array
    {
        return array_values(array_filter(
            self::allTypes(),
            fn (string $type) => (bool) (self::definitions()[$type]['scheduled'] ?? false),
        ));
    }

    /**
     * @return array<string, array{
     *   label: string,
     *   scheduled: bool,
     *   default_lookback?: int,
     *   default_time?: string,
     *   prompt: string
     * }>
     */
    public static function definitions(): array
    {
        return [
            'stock_pulse' => [
                'label' => 'Stock pulse',
                'scheduled' => true,
                'default_lookback' => 14,
                'default_time' => '07:00',
                'prompt' => <<<'PROMPT'
You are Centrix stock intelligence for a Kenyan wholesale/retail business (KES).
Using the JSON data:
1) List fast-moving items (from fast_movers).
2) List items below stock / reorder (from low_stock_items) with suggested reorder focus.
3) Give short, practical purchasing advice.
Return JSON only with keys: summary (string), findings (array of strings), actions (array of {label, href?}).
PROMPT,
            ],
            'sales_brief' => [
                'label' => 'Sales brief',
                'scheduled' => true,
                'default_lookback' => 7,
                'default_time' => '07:00',
                'prompt' => <<<'PROMPT'
You are Centrix sales intelligence for Kenyan field/backoffice sales (KES).
Summarize period performance vs previous period, top products/customers, unpaid risk, and 3 actions managers should take.
Return JSON only with keys: summary (string), findings (array of strings), actions (array of {label, href?}).
PROMPT,
            ],
            'debtors_brief' => [
                'label' => 'Credit / debtors brief',
                'scheduled' => true,
                'default_lookback' => 30,
                'default_time' => '07:15',
                'prompt' => <<<'PROMPT'
You are Centrix credit collections intelligence (KES, Kenya).
From the data: identify overdue debtors, concentration risk (top balances share), slow payers vs high-volume customers, and produce a "call these 5 today" list with suggested ask amounts (installments OK).
Return JSON only with keys: summary, findings, actions (prefer actions_hint hrefs).
PROMPT,
            ],
            'cash_till_health' => [
                'label' => 'Cash & till health',
                'scheduled' => true,
                'default_lookback' => 14,
                'default_time' => '07:20',
                'prompt' => <<<'PROMPT'
You are Centrix cash/till health analyst (KES).
Explain over/short patterns, blind-close outliers, M-Pesa vs cash mix, and float adequacy. Flag sessions needing review.
Return JSON only with keys: summary, findings, actions.
PROMPT,
            ],
            'route_mobile_debrief' => [
                'label' => 'Route / mobile day debrief',
                'scheduled' => true,
                'default_lookback' => 1,
                'default_time' => '18:00',
                'prompt' => <<<'PROMPT'
You are Centrix field sales debrief for mobile/route orders (KES).
Compare booked vs delivered/completed, unpaid on route, top SKUs, and stalled customers. Give 3 concrete follow-ups for tomorrow.
Return JSON only with keys: summary, findings, actions.
PROMPT,
            ],
            'exception_radar' => [
                'label' => 'Exception radar',
                'scheduled' => true,
                'default_lookback' => 7,
                'default_time' => '07:05',
                'prompt' => <<<'PROMPT'
You are Centrix morning exception radar.
Combine low stock, unpaid/AR spike, unusual discounts, and void/cancel bursts into one prioritized digest. Separate noise from must-act items.
Return JSON only with keys: summary, findings, actions.
PROMPT,
            ],
            'product_demand' => [
                'label' => 'Product demand intelligence',
                'scheduled' => false,
                'default_lookback' => 30,
                'prompt' => <<<'PROMPT'
You are Centrix product demand intelligence (KES).
For the product focus (or top movers if none): who bought it, velocity vs stock, suggested reorder qty, dead/slow stock risks.
Return JSON only with keys: summary, findings, actions.
PROMPT,
            ],
            'customer_360' => [
                'label' => 'Customer 360',
                'scheduled' => false,
                'default_lookback' => 90,
                'prompt' => <<<'PROMPT'
You are Centrix customer 360 analyst (KES).
Cover credit limit usage, payment habit, last purchase mix, and whether they look likely to churn or reorder. Be concrete; do not invent history.
Return JSON only with keys: summary, findings, actions.
PROMPT,
            ],
            'margin_discount_watchdog' => [
                'label' => 'Margin & discount watchdog',
                'scheduled' => true,
                'default_lookback' => 14,
                'default_time' => '07:25',
                'prompt' => <<<'PROMPT'
You are Centrix margin & discount watchdog (KES).
Highlight lines sold below cost, discount approval backlog, and cashiers/users with high discount rates. Suggest controls, not blame.
Return JSON only with keys: summary, findings, actions.
PROMPT,
            ],
            'procurement_companion' => [
                'label' => 'Procurement companion',
                'scheduled' => false,
                'default_lookback' => 14,
                'prompt' => <<<'PROMPT'
You are Centrix procurement companion.
From low stock + velocity, draft LPO suggestions (supplier if known, qty, urgency). User must confirm — phrase actions as drafts to open/create.
Return JSON only with keys: summary, findings, actions (include href /lpo when useful).
PROMPT,
            ],
            'collections_playbook' => [
                'label' => 'Aging + collections playbook',
                'scheduled' => true,
                'default_lookback' => 60,
                'default_time' => '07:30',
                'prompt' => <<<'PROMPT'
You are Centrix collections playbook (KES).
Build a prioritized call list with suggested ask amounts (full or installment). Group by aging bucket. Prefer practical scripts in findings.
Return JSON only with keys: summary, findings, actions.
PROMPT,
            ],
            'anomaly_detection' => [
                'label' => 'Anomaly detection',
                'scheduled' => true,
                'default_lookback' => 7,
                'default_time' => '07:35',
                'prompt' => <<<'PROMPT'
You are Centrix anomaly detection.
Flag unusual order sizes, price overrides / deep discounts, after-hours sales, and same-customer multi-branch spikes. Rank by risk.
Return JSON only with keys: summary, findings, actions.
PROMPT,
            ],
            'forecast_light' => [
                'label' => 'Demand forecast (light)',
                'scheduled' => true,
                'default_lookback' => 30,
                'default_time' => '07:40',
                'prompt' => <<<'PROMPT'
You are Centrix demand narrator. The JSON already has statistical 7/14/30-day run-rates by SKU/route.
Narrate the forecast; do not invent numbers beyond the data. Suggest stock/route prep actions.
Return JSON only with keys: summary, findings, actions.
PROMPT,
            ],
            'branch_till_benchmarks' => [
                'label' => 'Branch / till benchmarks',
                'scheduled' => true,
                'default_lookback' => 14,
                'default_time' => '07:45',
                'prompt' => <<<'PROMPT'
You are Centrix branch/till peer benchmark (same organization only).
Compare branches/tills on sales, variance, and payment mix. Never invent other tenants. Call out leaders and laggards fairly.
Return JSON only with keys: summary, findings, actions.
PROMPT,
            ],
            'explain_screen' => [
                'label' => 'Explain this screen',
                'scheduled' => false,
                'prompt' => <<<'PROMPT'
You are Centrix page-aware analyst.
The user is viewing a screen with the attached filters/rows/summary. Answer: what stands out here? Be specific to the visible slice; suggest next clicks via actions_hint.
Return JSON only with keys: summary, findings, actions.
PROMPT,
            ],
        ];
    }

    public static function label(string $type): string
    {
        return (string) (self::definitions()[$type]['label'] ?? 'AI Insight');
    }

    public static function prompt(string $type): string
    {
        return (string) (self::definitions()[$type]['prompt'] ?? 'Analyze this Centrix ERP data. Return JSON with summary, findings, actions.');
    }

    public static function isKnown(string $type): bool
    {
        return array_key_exists($type, self::definitions());
    }
}
