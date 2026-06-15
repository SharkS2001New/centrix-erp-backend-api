<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\Reports\ReportBuilderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiAssistantService
{
    public function __construct(protected ReportBuilderService $reportBuilder) {}

    public function isAvailableForUser(User $user): bool
    {
        return AiSettingsResolver::isAvailableForUser($user);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{reply: string, tools_used: array<int, string>, data?: array<string, mixed>}
     */
    public function chat(User $user, string $context, string $message, array $history = []): array
    {
        $runtime = AiSettingsResolver::resolveRuntime($user);
        if (! $runtime) {
            $settings = AiSettingsResolver::forUser($user);

            return [
                'reply' => ! ($settings['enabled'] ?? false)
                    ? 'AI assistant is disabled for this organization. An admin can enable it under Administration → Settings → AI.'
                    : 'AI assistant is not configured for this organization. An admin must add an OpenAI API key under Administration → Settings → AI.',
                'tools_used' => [],
            ];
        }

        $system = $this->systemPrompt($context);
        $messages = [['role' => 'system', 'content' => $system]];
        foreach (array_slice($history, -8) as $turn) {
            if (! empty($turn['role']) && ! empty($turn['content'])) {
                $messages[] = ['role' => $turn['role'], 'content' => (string) $turn['content']];
            }
        }

        $toolContext = $this->gatherToolContext($user, $context, $message);
        if ($toolContext) {
            $messages[] = [
                'role' => 'system',
                'content' => "Live ERP data (use in your answer, do not invent numbers):\n".json_encode($toolContext, JSON_PRETTY_PRINT),
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        try {
            $response = Http::withToken($runtime['api_key'])
                ->timeout(45)
                ->post($runtime['base_url'].'/chat/completions', [
                    'model' => $runtime['model'],
                    'messages' => $messages,
                    'max_tokens' => config('ai.defaults.max_tokens'),
                    'temperature' => 0.3,
                ]);

            if (! $response->successful()) {
                Log::warning('AI chat failed', ['status' => $response->status(), 'body' => $response->body()]);

                return [
                    'reply' => $this->formatApiFailure($response->status(), $response->json('error.message') ?? $response->body()),
                    'tools_used' => array_keys($toolContext ?? []),
                    'error_code' => $response->json('error.code') ?? (string) $response->status(),
                ];
            }

            $reply = trim($response->json('choices.0.message.content') ?? '');
            if ($reply === '') {
                $reply = 'I could not generate a response. Please try rephrasing your question.';
            }

            return [
                'reply' => $reply,
                'tools_used' => array_keys($toolContext ?? []),
                'data' => $toolContext ?: null,
            ];
        } catch (\Throwable $e) {
            Log::error('AI chat exception', ['message' => $e->getMessage()]);

            return [
                'reply' => 'AI assistant could not reach the provider. Check network access and OPENAI_BASE_URL.',
                'tools_used' => array_keys($toolContext ?? []),
            ];
        }
    }

    protected function formatApiFailure(int $status, ?string $providerMessage): string
    {
        $detail = trim((string) $providerMessage);
        if (strlen($detail) > 240) {
            $detail = substr($detail, 0, 237).'…';
        }

        return match ($status) {
            401 => 'OpenAI rejected the API key (401). Verify OPENAI_API_KEY in .env and restart the API server.'
                .($detail ? " Provider: {$detail}" : ''),
            403 => 'OpenAI access denied (403). Your key may lack permission for this model.'
                .($detail ? " Provider: {$detail}" : ''),
            429 => 'OpenAI quota exceeded (429). Add billing or credits at https://platform.openai.com/account/billing'
                .($detail ? " — {$detail}" : ''),
            404 => 'Model not found (404). Set OPENAI_MODEL to a model your account can use (e.g. gpt-4o-mini).'
                .($detail ? " Provider: {$detail}" : ''),
            default => 'AI request failed (HTTP '.$status.').'
                .($detail ? " {$detail}" : ' Check OPENAI_API_KEY, OPENAI_MODEL, and billing.'),
        };
    }

    protected function systemPrompt(string $context): string
    {
        $base = 'You are a helpful assistant for a POS/ERP system used in Kenya (currency KES). '
            .'Be concise, actionable, and cite numbers from provided data only. '
            .'If data is missing, say what report or screen to check.';

        return match ($context) {
            'products' => $base.' You help with catalog, stock levels, reorder points, pricing, and KRA product registration.',
            'reports' => $base.' You help interpret sales, inventory, receivables, and custom reports. Suggest specific built-in reports when useful.',
            'report_builder' => $base.' You help users design custom reports from allowlisted sources: sales, sale_items, customers, stock, invoices. '
                .'Recommend group-by fields and aggregates; never suggest raw SQL.',
            default => $base,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function gatherToolContext(User $user, string $context, string $message): array
    {
        $lower = strtolower($message);
        $data = [];

        if ($context === 'products' || str_contains($lower, 'stock') || str_contains($lower, 'product') || str_contains($lower, 'reorder')) {
            $data['product_summary'] = $this->productSummary($user);
        }

        if ($context === 'reports' || str_contains($lower, 'sales') || str_contains($lower, 'report') || str_contains($lower, 'profit')) {
            $data['sales_summary'] = $this->salesSummary($user);
            $data['report_sources'] = array_column($this->reportBuilder->schema()['sources'] ?? [], 'label', 'key');
        }

        if ($context === 'report_builder') {
            $data['report_builder_schema'] = $this->reportBuilder->schema();
        }

        if (str_contains($lower, 'receivable') || str_contains($lower, 'debt') || str_contains($lower, 'invoice')) {
            $data['receivables_summary'] = $this->receivablesSummary($user);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function productSummary(User $user): array
    {
        $orgId = $user->organization_id;

        $totalProducts = DB::table('products')
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->count();

        $lowStock = DB::table('current_stock as cs')
            ->join('products as p', 'p.product_code', '=', 'cs.product_code')
            ->where('p.organization_id', $orgId)
            ->whereNull('p.deleted_at')
            ->where('p.low_stock_alert_enabled', true)
            ->whereRaw('(cs.shop_quantity + cs.store_quantity) <= COALESCE(p.reorder_point, 0)')
            ->whereRaw('(cs.shop_quantity + cs.store_quantity) > 0')
            ->count();

        $outOfStock = DB::table('current_stock as cs')
            ->join('products as p', 'p.product_code', '=', 'cs.product_code')
            ->where('p.organization_id', $orgId)
            ->whereNull('p.deleted_at')
            ->whereRaw('(cs.shop_quantity + cs.store_quantity) <= 0')
            ->count();

        $topLow = DB::table('current_stock as cs')
            ->join('products as p', 'p.product_code', '=', 'cs.product_code')
            ->where('p.organization_id', $orgId)
            ->whereNull('p.deleted_at')
            ->where('p.low_stock_alert_enabled', true)
            ->whereRaw('(cs.shop_quantity + cs.store_quantity) <= COALESCE(p.reorder_point, 0)')
            ->orderByRaw('(cs.shop_quantity + cs.store_quantity) asc')
            ->limit(5)
            ->get(['p.product_code', 'p.product_name', 'cs.shop_quantity', 'cs.store_quantity', 'p.reorder_point']);

        return [
            'total_products' => $totalProducts,
            'low_stock_skus' => $lowStock,
            'out_of_stock_skus' => $outOfStock,
            'urgent_reorder' => $topLow,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function salesSummary(User $user): array
    {
        $from = now()->subDays(29)->toDateString();
        $to = now()->toDateString();

        $q = DB::table('sales')
            ->where('organization_id', $user->organization_id)
            ->where('status', 'completed')
            ->where('archived', 0)
            ->whereDate('completed_at', '>=', $from)
            ->whereDate('completed_at', '<=', $to);

        return [
            'period' => ['from' => $from, 'to' => $to],
            'total_sales_kes' => (float) $q->sum('order_total'),
            'order_count' => (int) $q->count(),
            'by_channel' => DB::table('sales')
                ->where('organization_id', $user->organization_id)
                ->where('status', 'completed')
                ->where('archived', 0)
                ->whereDate('completed_at', '>=', $from)
                ->whereDate('completed_at', '<=', $to)
                ->selectRaw('channel, SUM(order_total) as revenue, COUNT(*) as orders')
                ->groupBy('channel')
                ->orderByDesc('revenue')
                ->limit(5)
                ->get()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function receivablesSummary(User $user): array
    {
        return [
            'total_outstanding_kes' => (float) DB::table('customer_invoices')
                ->where('organization_id', $user->organization_id)
                ->whereNull('deleted_at')
                ->where('balance_due', '>', 0)
                ->sum('balance_due'),
            'open_invoices' => (int) DB::table('customer_invoices')
                ->where('organization_id', $user->organization_id)
                ->whereNull('deleted_at')
                ->where('balance_due', '>', 0)
                ->count(),
            'top_debtors' => DB::table('customers')
                ->where('organization_id', $user->organization_id)
                ->whereNull('deleted_at')
                ->where('current_balance', '>', 0)
                ->orderByDesc('current_balance')
                ->limit(5)
                ->get(['customer_num', 'customer_name', 'current_balance'])
                ->all(),
        ];
    }
}
