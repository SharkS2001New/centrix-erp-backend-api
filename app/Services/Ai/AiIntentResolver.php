<?php

namespace App\Services\Ai;

class AiIntentResolver
{
    /**
     * Infer a create action from the user message and recent history when the LLM
     * did not emit an action block (e.g. "please hold on while I fetch options").
     *
     * @param  array<int, array{role?: string, content?: string}>  $history
     * @return array<string, mixed>|null
     */
    public function inferCreateAction(string $message, array $history = []): ?array
    {
        $text = strtolower($message);
        foreach (array_slice($history, -6) as $turn) {
            if (($turn['role'] ?? '') === 'user') {
                $text .= ' '.strtolower((string) ($turn['content'] ?? ''));
            }
        }

        if ($this->matchesProductCreate($text)) {
            return [
                'type' => 'create_product',
                'summary' => $this->productSummary($message, $history),
                'params' => $this->extractProductParams($message, $history),
            ];
        }

        if ($this->matchesEmployeeCreate($text)) {
            return [
                'type' => 'create_employee',
                'summary' => 'Create employee',
                'params' => $this->extractEmployeeParams($message, $history),
            ];
        }

        if ($this->matchesOrderCreate($text)) {
            return [
                'type' => $this->matchesHeldOrder($text) ? 'create_held_order' : 'create_sales_order',
                'summary' => $this->matchesHeldOrder($text) ? 'Save held order' : 'Create sales order',
                'params' => $this->extractOrderParams($message, $history),
            ];
        }

        if ($this->matchesPaymentRecord($text)) {
            return [
                'type' => 'record_customer_payment',
                'summary' => $this->paymentSummary($message, $history),
                'params' => $this->extractPaymentParams($message, $history),
            ];
        }

        return null;
    }

    protected function matchesProductCreate(string $text): bool
    {
        return (bool) preg_match('/\b(create|add|new|register)\b.*\b(product|item|sku|catalog)/i', $text)
            || (bool) preg_match('/\b(product|item|sku)\b.*\b(create|add|new)\b/i', $text);
    }

    protected function matchesEmployeeCreate(string $text): bool
    {
        return (bool) preg_match('/\b(create|add|new|hire)\b.*\b(employee|staff|worker)/i', $text);
    }

    protected function matchesOrderCreate(string $text): bool
    {
        return (bool) preg_match('/\b(create|add|new|place)\b.*\b(order|sale)/i', $text)
            || (bool) preg_match('/\b(order|sale)\b.*\b(create|add|new)\b/i', $text);
    }

    protected function matchesHeldOrder(string $text): bool
    {
        return (bool) preg_match('/\b(hold|held|save only|save for later|without payment)\b/i', $text);
    }

    protected function matchesPaymentRecord(string $text): bool
    {
        return (bool) preg_match('/\b(record|post|apply|enter|mark)\b.*\b(payment|paid|pay)\b/i', $text)
            || (bool) preg_match('/\b(partial|full)\s+payment\b/i', $text)
            || (bool) preg_match('/\bmark\b.*\b(paid|payment)\b/i', $text)
            || (bool) preg_match('/\bpay\b.*\b(invoice|order|debt|balance|customer)\b/i', $text);
    }

    /** @param  array<int, array{role?: string, content?: string}>  $history
     * @return array<string, mixed>
     */
    protected function extractProductParams(string $message, array $history): array
    {
        $params = [];
        $sources = [$message];
        foreach (array_reverse($history) as $turn) {
            if (($turn['role'] ?? '') === 'user') {
                $sources[] = (string) ($turn['content'] ?? '');
            }
        }

        foreach ($sources as $source) {
            if (preg_match('/(?:named|called|name(?:d)?)\s+["\']([^"\']+)["\']/i', $source, $m)) {
                $params['product_name'] = trim($m[1]);
            } elseif (preg_match('/(?:named|called|name(?:d)?)\s+([A-Za-z0-9][A-Za-z0-9 \-]{1,80})/i', $source, $m)) {
                $params['product_name'] = trim($m[1]);
            }
            if (preg_match('/(?:price|at|for)\s+(?:kes\s*)?(\d+(?:\.\d+)?)/i', $source, $m)) {
                $params['unit_price'] = (float) $m[1];
            }
            if (preg_match('/(?:code|sku)\s+["\']?([A-Za-z0-9\-#]+)["\']?/i', $source, $m)) {
                $params['product_code'] = trim($m[1]);
            }
        }

        return array_filter($params, fn ($v) => $v !== null && $v !== '');
    }

    /** @param  array<int, array{role?: string, content?: string}>  $history
     * @return array<string, mixed>
     */
    protected function extractEmployeeParams(string $message, array $history): array
    {
        $params = [];
        $blob = $message;
        foreach ($history as $turn) {
            if (($turn['role'] ?? '') === 'user') {
                $blob .= ' '.($turn['content'] ?? '');
            }
        }
        if (preg_match('/first name\s+["\']?([^,"\']+)["\']?/i', $blob, $m)) {
            $params['first_name'] = trim($m[1]);
        }
        if (preg_match('/last name\s+["\']?([^,"\']+)["\']?/i', $blob, $m)) {
            $params['last_name'] = trim($m[1]);
        }

        return $params;
    }

    /** @param  array<int, array{role?: string, content?: string}>  $history
     * @return array<string, mixed>
     */
    protected function extractOrderParams(string $message, array $history): array
    {
        return [];
    }

    /** @param  array<int, array{role?: string, content?: string}>  $history
     * @return array<string, mixed>
     */
    protected function extractPaymentParams(string $message, array $history): array
    {
        $params = [];
        $blob = $message;
        foreach ($history as $turn) {
            if (($turn['role'] ?? '') === 'user') {
                $blob .= ' '.($turn['content'] ?? '');
            }
        }

        if (preg_match('/\b(?:order|invoice)\s*(?:#|num(?:ber)?)?\s*["\']?([A-Za-z0-9\-]+)["\']?/i', $blob, $m)) {
            $params['order_num'] = trim($m[1]);
        }
        if (preg_match('/\b(?:kes|ksh|amount|pay)\s*(\d+(?:\.\d+)?)/i', $blob, $m)) {
            $params['amount'] = (float) $m[1];
        }
        if (preg_match('/\b(mark\s+(?:as\s+)?paid|full\s+payment|pay\s+in\s+full)\b/i', $blob)) {
            $params['mark_paid_full'] = true;
        }

        return array_filter($params, fn ($v) => $v !== null && $v !== '');
    }

    /** @param  array<int, array{role?: string, content?: string}>  $history */
    protected function paymentSummary(string $message, array $history): string
    {
        $params = $this->extractPaymentParams($message, $history);

        if (! empty($params['mark_paid_full'])) {
            return 'Record full payment';
        }
        if (! empty($params['amount'])) {
            return 'Record partial payment: KES '.$params['amount'];
        }

        return 'Record customer payment';
    }

    /** @param  array<int, array{role?: string, content?: string}>  $history */
    protected function productSummary(string $message, array $history): string
    {
        $params = $this->extractProductParams($message, $history);

        return ! empty($params['product_name'])
            ? 'Create product: '.$params['product_name']
            : 'Create product';
    }
}
