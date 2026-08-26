<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiReplyFormatter;
use Tests\TestCase;

class AiReplyFormatterTest extends TestCase
{
    public function test_converts_latex_stock_formula_to_plain_field_names(): void
    {
        $formatter = new AiReplyFormatter;
        $input = '$$\text{Stock Value} = \text{Cost Price} \times \text{Stock on Hand}$$';

        $this->assertSame(
            'Stock Value = Cost Price × Stock on Hand',
            $formatter->format($input),
        );
    }

    public function test_rewrites_known_bad_paths(): void
    {
        $formatter = new AiReplyFormatter;
        $out = $formatter->format('Open field attendance at /hr/field-attendance');

        $this->assertStringContainsString('/sales/field-attendance', $out);
        $this->assertStringNotContainsString('/hr/field-attendance', $out);
    }

    public function test_keeps_custom_report_detail_paths(): void
    {
        $formatter = new AiReplyFormatter;
        $out = $formatter->format('Open your report at /reports/custom/42');

        $this->assertStringContainsString('/reports/custom/42', $out);
    }

    public function test_strips_code_column_when_product_name_present(): void
    {
        $formatter = new AiReplyFormatter;
        $input = <<<'MD'
Here are the top products:

| # | Product | Code | Qty | Amount (KES) |
| --- | --- | --- | --- | ---: |
| 1 | SUGAR 50 KG | 1300009 | 4,468 bag | 28,496,825.79 |
| 2 | POST MAN 20LTRS | 1264002 | 2,100 Pcs | 7,500,000.00 |
MD;

        $out = $formatter->format($input);

        $this->assertStringContainsString('| Product |', $out);
        $this->assertStringContainsString('SUGAR 50 KG', $out);
        $this->assertStringNotContainsString('| Code |', $out);
        $this->assertStringNotContainsString('1300009', $out);
        $this->assertStringNotContainsString('1264002', $out);
    }
}
