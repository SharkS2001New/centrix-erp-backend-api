<?php

namespace App\Services\Ai;

class AiIntentResolver
{
    /**
     * Infer a create action from the user message, recent history, and current page
     * when the LLM did not emit an action block.
     *
     * @param  array<int, array{role?: string, content?: string}>  $history
     * @return array<string, mixed>|null
     */
    public function isDataQuestion(string $message): bool
    {
        $text = $this->normalizeForIntent($message);

        return (bool) preg_match(
            '/\b(sales|sold|revenue|turnover|daily|weekly|monthly|yesterday|today|last\s+week|cashier|till|stock|inventory|debtor|receivable|report|summary|how\s+much|how\s+many|total|analytics|performance|margin|forecast|best\s+seller|top\s+seller|attendance|clock(?:ed|s)?|present|absent|late|lateness)\b/i',
            $text,
        );
    }

    public function isCancelIntent(string $message): bool
    {
        return (bool) preg_match(
            '/^(cancel|never\s*mind|forget\s+(?:that|it)|stop|discard|ignore)\b/i',
            $this->normalizeForIntent($message),
        );
    }

    public function inferCreateAction(string $message, array $history = [], ?string $pathname = null): ?array
    {
        if ($this->isCancelIntent($message)) {
            return null;
        }

        // Match on wording — ignore trailing punctuation / stray symbols (?, /, !, …).
        $text = $this->normalizeForIntent($message);

        // Pure data questions skip create inference — but "create LPO from yesterday's sales"
        // still has a create verb and must proceed.
        if ($this->isDataQuestion($message) && ! $this->hasWriteVerb($text)) {
            return null;
        }

        $pathEntity = $this->entityFromPath($pathname);

        if (($pathEntity === 'product' || $pathEntity === null) && $this->matchesProductCreate($text)) {
            return [
                'type' => 'create_product',
                'summary' => $this->productSummary($message, $history),
                'params' => $this->extractProductParams($message, $history),
            ];
        }

        if (($pathEntity === 'supplier' || ($pathEntity === null && $this->matchesSupplierCreate($text))) && $this->matchesSupplierCreate($text)) {
            return [
                'type' => 'create_supplier',
                'summary' => $this->supplierSummary($message, $history),
                'params' => $this->extractNamedParam($message, $history, 'supplier_name'),
            ];
        }

        if (($pathEntity === 'customer' || ($pathEntity === null && $this->matchesCustomerCreate($text))) && $this->matchesCustomerCreate($text)) {
            return [
                'type' => 'create_customer',
                'summary' => $this->customerSummary($message, $history),
                'params' => $this->extractNamedParam($message, $history, 'customer_name'),
            ];
        }

        if ($this->matchesEmployeeCreate($text)) {
            return [
                'type' => 'create_employee',
                'summary' => 'Create employee',
                'params' => $this->extractEmployeeParams($message, $history),
            ];
        }

        if ($workflow = $this->inferLpoWorkflowAction($message, $history)) {
            return $workflow;
        }

        if ($this->matchesLpoCreate($text)) {
            return [
                'type' => 'create_lpo',
                'summary' => 'Create purchase order (LPO)',
                'params' => $this->extractLpoParams($message, $history),
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

        if ($nav = $this->inferNavigateOrders($message)) {
            return $nav;
        }

        if ($this->matchesOpenLpo($text)) {
            $lpoNo = $this->extractLpoNumber($message, $history);

            return [
                'type' => 'open_lpo',
                'summary' => $lpoNo
                    ? "Open purchase order (LPO) {$lpoNo}"
                    : 'Open purchase orders (LPO)',
                'params' => array_filter([
                    'href' => $lpoNo ? '/lpo/'.$lpoNo : '/lpo',
                    'lpo_no' => $lpoNo,
                ]),
            ];
        }

        return null;
    }

    /**
     * Submit / approve / send / receive an existing LPO from chat.
     *
     * @param  array<int, array{role?: string, content?: string}>  $history
     * @return array<string, mixed>|null
     */
    protected function inferLpoWorkflowAction(string $message, array $history = []): ?array
    {
        $text = $this->normalizeForIntent($message);
        if (! preg_match('/\b(lpo|purchase\s+orders?|grn|goods\s+received)\b/', $text)
            && ! preg_match('/\breceive\b.{0,30}\b(stock|goods|delivery)\b/', $text)) {
            return null;
        }

        if ($this->matchesLpoCreate($text)) {
            return null;
        }

        $lpoNo = $this->extractLpoNumber($message, $history);
        $params = array_filter(['lpo_no' => $lpoNo]);

        if (preg_match('/\b(submit|send)\b.{0,40}\b(for\s+)?approv/i', $text)
            || preg_match('/\bapprov(?:al|e)\s+request\b/i', $text)) {
            return [
                'type' => 'submit_lpo_for_approval',
                'summary' => $lpoNo
                    ? "Submit LPO {$lpoNo} for approval"
                    : 'Submit LPO for approval',
                'params' => $params,
            ];
        }

        if (preg_match('/\bapprove\b.{0,40}\b(lpo|purchase\s+order)\b/i', $text)
            || preg_match('/\b(lpo|purchase\s+order)\b.{0,40}\bapprove\b/i', $text)) {
            return [
                'type' => 'approve_lpo',
                'summary' => $lpoNo ? "Approve LPO {$lpoNo}" : 'Approve LPO',
                'params' => $params,
            ];
        }

        if (preg_match('/\b(mark\s+)?sent\b.{0,40}\b(lpo|purchase\s+order|supplier)\b/i', $text)
            || preg_match('/\b(lpo|purchase\s+order)\b.{0,40}\b(mark\s+)?sent\b/i', $text)
            || preg_match('/\bsend\b.{0,40}\b(lpo|purchase\s+order)\b.{0,20}\b(to\s+)?supplier\b/i', $text)) {
            return [
                'type' => 'mark_lpo_sent',
                'summary' => $lpoNo ? "Mark LPO {$lpoNo} as sent" : 'Mark LPO as sent',
                'params' => $params,
            ];
        }

        if (preg_match('/\breceive\b.{0,50}\b(lpo|purchase\s+order|goods|stock|delivery|grn)\b/i', $text)
            || preg_match('/\b(lpo|purchase\s+order|grn)\b.{0,40}\breceive\b/i', $text)
            || preg_match('/\bgoods\s+received\b/i', $text)) {
            $params['receive_all'] = true;

            return [
                'type' => 'receive_lpo_goods',
                'summary' => $lpoNo
                    ? "Receive goods for LPO {$lpoNo}"
                    : 'Receive LPO goods into stock',
                'params' => $params,
            ];
        }

        return null;
    }

    /**
     * @param  array<int, array{role?: string, content?: string}>  $history
     */
    protected function extractLpoNumber(string $message, array $history = []): ?int
    {
        $sources = [$message];
        foreach (array_reverse($history) as $turn) {
            if (($turn['role'] ?? '') === 'user') {
                $sources[] = (string) ($turn['content'] ?? '');
            }
        }
        $blob = implode("\n", $sources);

        if (preg_match('/\b(?:lpo|po|purchase\s+order)\s*[#:]?\s*(\d{1,12})\b/i', $blob, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/\b(?:number|no\.?|#)\s*(\d{1,12})\b/i', $blob, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Natural-language ops → filtered orders list (not a paragraph).
     *
     * @return array<string, mixed>|null
     */
    protected function inferNavigateOrders(string $message): ?array
    {
        $text = $this->normalizeForIntent($message);
        if (preg_match('/\b(lpo|purchase\s+orders?)\b/', $text)) {
            return null;
        }
        if (! preg_match('/\b(show|list|find|filter|open)\b.*\b(order|orders|sale|sales)\b/', $text)
            && ! preg_match('/\borders?\s+(with|containing|for)\b/', $text)
            && ! preg_match('/\bwho\s+bought\b/', $text)) {
            return null;
        }

        $params = ['href' => '/sales/orders'];
        $qParts = [];

        if (preg_match('/\bunpaid\b|\bpending\s+payment\b|\bcredit\b|\bdebtor/', $text)) {
            $params['href'] = '/sales/orders/queues/pending_payment';
        } elseif (preg_match('/\bmobile\b|\broute\b/', $text)) {
            $params['href'] = '/sales/orders/queues/mobile';
        }

        if (preg_match('/\b(?:with|containing|bought|for)\s+([a-z0-9][\w\s\-]{1,60})/i', $message, $m)) {
            $term = trim($m[1]);
            $term = preg_replace('/\b(unpaid|this week|today|orders?|mobile|credit)\b/i', '', $term) ?? $term;
            $term = trim($term);
            if ($term !== '') {
                $qParts[] = $term;
            }
        }

        if (preg_match('/\bthis\s+week\b/', $text)) {
            $params['note'] = 'Apply the list date filter to this week after opening.';
        }

        if ($qParts !== []) {
            $params['q'] = implode(' ', $qParts);
            $params['href'] .= (str_contains($params['href'], '?') ? '&' : '?').'q='.rawurlencode($params['q']);
        }

        return [
            'type' => 'navigate_orders',
            'summary' => 'Open filtered sales orders'.(! empty($params['q']) ? ': '.$params['q'] : ''),
            'params' => $params,
        ];
    }

    protected function matchesLpoCreate(string $text): bool
    {
        if (preg_match('/\b(create|draft|make|raise|generate|issue|new|save|add|prepare)\b.{0,50}\b(lpo|purchase\s+orders?|po)\b/', $text)) {
            return true;
        }
        if (preg_match('/\b(lpo|purchase\s+orders?|po)\b.{0,40}\b(create|draft|make|raise|generate|issue|save|add)\b/', $text)) {
            return true;
        }
        if (preg_match('/\b(lpo|purchase\s+order)\b.{0,40}\b(from|for)\b.{0,40}\b(order|sale|sales)\b/', $text)) {
            return true;
        }
        if (preg_match('/\b(gave|give|giving)\b.{0,30}\border\b.{0,40}\b(lpo|purchase\s+order)\b/', $text)) {
            return true;
        }
        if (preg_match('/\b(can\s+you|could\s+you|please|help\s+(me\s+)?(to\s+)?)\b.{0,40}\b(create|draft|make|raise|save)\b.{0,40}\b(lpo|purchase\s+order)\b/', $text)) {
            return true;
        }
        if (preg_match('/\b(help|want|need)\b.{0,40}\b(create|creating|make|making|save|saving)\b.{0,40}\b(lpo|purchase\s+order)\b/', $text)) {
            return true;
        }

        return false;
    }

    /** True when the message looks like a create/write request (not a pure data question). */
    protected function hasWriteVerb(string $text): bool
    {
        return (bool) preg_match(
            '/\b(create|draft|make|raise|generate|issue|new|save|add|record|submit|approve|prepare)\b/i',
            $text,
        );
    }

    protected function matchesOpenLpo(string $text): bool
    {
        if ($this->matchesLpoCreate($text)) {
            return false;
        }

        return (bool) preg_match('/\b(open|show|list|view|where)\b.{0,40}\b(lpo|purchase\s+orders?)\b/', $text)
            || ((bool) preg_match('/\b(lpo|purchase\s+orders?)\b/', $text)
                && (bool) preg_match('/\b(suggest|need|find)\b/', $text));
    }

    /**
     * @param  array<int, array{role?: string, content?: string}>  $history
     * @return array<string, mixed>
     */
    protected function extractLpoParams(string $message, array $history = []): array
    {
        $params = [];
        $sources = [$message];
        foreach (array_reverse($history) as $turn) {
            if (($turn['role'] ?? '') === 'user') {
                $sources[] = (string) ($turn['content'] ?? '');
            }
        }
        $blob = implode("\n", $sources);

        if (preg_match('/\b(?:order|sale|receipt)\s*[#:]?\s*([A-Z0-9][\w\-\/]{2,40})\b/i', $blob, $m)) {
            $params['order_num'] = trim($m[1]);
        } elseif (preg_match('/\b(ORD[-\w]+|SO[-\w]+|POS[-\w]+)\b/i', $blob, $m)) {
            $params['order_num'] = trim($m[1]);
        }

        if (preg_match('/\bsale_id\s*[:=]?\s*(\d+)\b/i', $blob, $m)) {
            $params['sale_id'] = (int) $m[1];
        }

        return $params;
    }

    /**
     * Focus matching on words, not trailing punctuation or stray symbols.
     * "…lpo for me?" / "…lpo for me /" / "…lpo for me!!!" → same intent text.
     */
    public function normalizeForIntent(string $message): string
    {
        $text = strtolower(trim($message));
        // Soft hyphens / zero-width / odd spaces
        $text = preg_replace('/[\x{00AD}\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        // Strip trailing punctuation / symbols the user typed instead of "?" (?, !, /, \, ., …, quotes, etc.)
        $text = preg_replace('/[\s\/\\\\|~`\'".,;:!?\-–—…•·]+$/u', '', $text) ?? $text;
        // Also strip a trailing run of the same after leftover spaces
        $text = rtrim($text);
        $text = preg_replace('/[\s\/\\\\|~`\'".,;:!?\-–—…•·]+$/u', '', $text) ?? $text;

        return trim($text);
    }

    protected function entityFromPath(?string $pathname): ?string
    {
        $path = '/'.trim((string) $pathname, '/');
        if ($path === '/') {
            return null;
        }

        $bestEntity = null;
        $bestLength = -1;

        foreach (config('ai_entity_schemas', []) as $entity => $schema) {
            $entityPath = (string) ($schema['path'] ?? '');
            if ($entityPath === '') {
                continue;
            }

            $prefix = rtrim($entityPath, '/');
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                $length = strlen($prefix);
                if ($length > $bestLength) {
                    $bestEntity = $entity;
                    $bestLength = $length;
                }
            }
        }

        return $bestEntity;
    }

    protected function matchesProductCreate(string $text): bool
    {
        return (bool) preg_match('/\b(create|add|new|register)\b.*\b(product|item|sku|catalog)/i', $text)
            || (bool) preg_match('/\b(product|item|sku)\b.*\b(create|add|new)\b/i', $text);
    }

    protected function matchesSupplierCreate(string $text): bool
    {
        if (preg_match('/\b(lpo|purchase\s+orders?)\b/', $text)) {
            return false;
        }

        return (bool) preg_match('/\b(create|add|new|register)\b.*\bsupplier/i', $text)
            || (bool) preg_match('/\bsupplier\b.*\b(create|add|new)\b/i', $text);
    }

    protected function matchesCustomerCreate(string $text): bool
    {
        return (bool) preg_match('/\b(create|add|new|register)\b.*\bcustomer/i', $text)
            || (bool) preg_match('/\bcustomer\b.*\b(create|add|new)\b/i', $text);
    }

    protected function matchesEmployeeCreate(string $text): bool
    {
        return (bool) preg_match('/\b(create|add|new|hire)\b.*\b(employee|staff|worker)/i', $text);
    }

    protected function matchesOrderCreate(string $text): bool
    {
        if ($this->matchesLpoCreate($text) || preg_match('/\bpurchase\s+order\b/', $text)) {
            return false;
        }

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
    protected function extractNamedParam(string $message, array $history, string $field): array
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
                $params[$field] = trim($m[1]);
            } elseif (preg_match('/(?:named|called|name(?:d)?)\s+([A-Za-z0-9][A-Za-z0-9 \-&]{1,80})/i', $source, $m)) {
                $params[$field] = trim($m[1]);
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

    /** @param  array<int, array{role?: string, content?: string}>  $history */
    protected function supplierSummary(string $message, array $history): string
    {
        $params = $this->extractNamedParam($message, $history, 'supplier_name');

        return ! empty($params['supplier_name'])
            ? 'Add supplier: '.$params['supplier_name']
            : 'Add supplier';
    }

    /** @param  array<int, array{role?: string, content?: string}>  $history */
    protected function customerSummary(string $message, array $history): string
    {
        $params = $this->extractNamedParam($message, $history, 'customer_name');

        return ! empty($params['customer_name'])
            ? 'Add customer: '.$params['customer_name']
            : 'Add customer';
    }
}
