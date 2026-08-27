<?php

namespace App\Services\Ai;

/**
 * Instant “Help” catalog so users who don’t know what to ask get a full menu.
 */
class AiAssistantHelpGuide
{
    public function isHelpRequest(string $message): bool
    {
        $text = strtolower(trim($message));
        if ($text === '') {
            return false;
        }

        $text = preg_replace('/[!?.…]+$/u', '', $text) ?? $text;
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        if (in_array($text, [
            'help',
            'help me',
            'help please',
            'please help',
            'menu',
            'commands',
            'what can you do',
            'what can i ask',
            'what can i do',
            'how do i use this',
            'how does this work',
            'examples',
            'sample questions',
            '?',
        ], true)) {
            return true;
        }

        // "Help me create…" is a create request, not the catalog.
        if (preg_match('/^help\b.{0,20}\b(create|draft|make|save|add|open|find|show|where|how)\b/i', $text)) {
            return false;
        }

        return (bool) preg_match(
            '/^(help(\s+me)?|what can (you|i) (do|ask)|show (me )?(examples|commands|menu))\s*$/i',
            $text,
        );
    }

    public function reply(?string $workspaceLabel = null): string
    {
        $scope = $workspaceLabel ? trim($workspaceLabel) : 'Centrix';

        return <<<MD
### Centrix AI — what you can ask

Type naturally. You are in **{$scope}**. Tip: type **Help** anytime to see this list again. Use **@** to mention a product, supplier, customer, user, or employee.

#### Sales & money
- What were yesterday’s / today’s / this week’s sales?
- Sales by cashier or by product (mention @Product)
- Who are our top debtors? Customer statement for @Customer
- How much VAT this month?
- Profit & loss / expenses for a person or period
- Check for abnormal / unusual sales this week

#### Stock & products
- Which items are in stock? / low stock?
- Stock value / inventory valuation
- Product packaging (kg vs bags) for @Product
- Price history for @Product

#### Purchasing (LPO)
- Create / save an LPO (share supplier + lines first; **confirm** only when ready to save)
- Show LPO status / download PDF
- Submit for approval, approve, mark sent, or receive goods

#### Mobile / routes
- Mobile sales yesterday for @User
- Expenses and returns by salesperson
- Who operates route X? / Which routes does @User run?

#### HR & payroll
- Attendance this month for @Employee
- Basic salary / payroll preview for @Employee

#### Where to go / how to
- Where is GRN / till / payroll / suppliers?
- How do I hold an order / open a till / run night audit?

#### Create in chat
- Create product, supplier, customer, employee, sales order, or LPO  
  Share details in chat first → when ready, reply **confirm** (or **show form**)

Ask one clear question next — for example: *“Yesterday’s sales”* or *“Create an LPO”*.
MD;
    }
}
