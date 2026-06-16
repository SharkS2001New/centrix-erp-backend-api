<?php

namespace App\Services\Ai;

class AiTopicGuard
{
    /** @var list<string> */
    protected const OFF_TOPIC_PATTERNS = [
        '/\b(weather|forecast|temperature|rain today)\b/i',
        '/\b(recipe|cook(ing)?|bake|ingredient)\b/i',
        '/\b(poem|joke|story|song lyrics|write me a)\b/i',
        '/\b(homework|essay|translate (this|to)|grammar check)\b/i',
        '/\b(capital of|who (is|was|won)|celebrity|movie|football score)\b/i',
        '/\b(stock market|crypto|bitcoin|forex trading)\b/i',
        '/\b(medical advice|diagnose|symptoms|prescription)\b/i',
        '/\b(legal advice|lawyer|sue|court case)\b/i',
        '/\b(python code|javascript code|write code for(?! order))\b/i',
    ];

    /** @var list<string> */
    protected const ERP_SIGNALS = [
        '/\b(order|sale|invoice|customer|product|catalog|catalogue|category|stock|inventory|sku|grn|lpo|supplier|purchase|expense|account|journal|dispatch|trip|route|driver|vehicle|pos|till|voucher|credit note|attendance|leave|department|shift|branch|employee|payroll|kpi|report|builder|settings|admin|module|screen|page|navigate|where (is|do)|how (do|can) i)\b/i',
        '/\b(create|add|new|hold|save|build|generate|show me|find|list|summarize|reorder|low stock|outstanding|receivable|debtor|checkout|cart|receive|transfer|adjust)\b/i',
        '/\b(kes|erp|pos|grn|nssf|paye|kra|mpesa|vat|uom|wholesale|retail)\b/i',
    ];

    public function isErpRelated(string $message): bool
    {
        $text = trim($message);
        if ($text === '') {
            return false;
        }

        foreach (self::ERP_SIGNALS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        foreach (self::OFF_TOPIC_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return false;
            }
        }

        if (preg_match('/^(hi|hello|hey|help|thanks|thank you|ok|yes|no|confirm|proceed|go ahead)\b/i', $text)) {
            return true;
        }

        return strlen($text) <= 400 || preg_match('/\b(help|system|app|this|erp|pos)\b/i', $text);
    }

    public function declineMessage(): string
    {
        return 'I can only help with this POS/ERP system — products, sales, inventory, purchasing, '
            .'accounting, HR, logistics, reports, and admin settings. '
            .'Ask me to find a screen, explain a workflow, or create records you have permission for.';
    }
}
