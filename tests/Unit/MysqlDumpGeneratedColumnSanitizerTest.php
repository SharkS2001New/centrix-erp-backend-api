<?php

namespace Tests\Unit;

use App\Services\Backup\MysqlDumpGeneratedColumnSanitizer;
use Tests\TestCase;

class MysqlDumpGeneratedColumnSanitizerTest extends TestCase
{
    public function test_rewrites_generated_columns_and_appends_restore_alters(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `customer_invoices` (
  `id` char(36) NOT NULL,
  `invoice_total` decimal(10,2) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT '0.00',
  `balance_due` decimal(10,2) GENERATED ALWAYS AS ((`invoice_total` - `amount_paid`)) STORED,
  PRIMARY KEY (`id`)
);
INSERT INTO `customer_invoices` (`id`, `invoice_total`, `amount_paid`, `balance_due`) VALUES ('a',10.00,4.00,6.00);
CREATE TABLE `stock_take_lines` (
  `id` int NOT NULL,
  `system_quantity` float NOT NULL,
  `counted_quantity` float NOT NULL,
  `variance` float GENERATED ALWAYS AS ((`counted_quantity` - `system_quantity`)) STORED
);
INSERT INTO `stock_take_lines` (`id`, `system_quantity`, `counted_quantity`, `variance`) VALUES (1,5,3,-2);
SQL;

        $result = (new MysqlDumpGeneratedColumnSanitizer)->sanitizeSql($sql);

        $this->assertTrue($result['rewritten']);
        $this->assertContains('customer_invoices', $result['tables']);
        $this->assertContains('stock_take_lines', $result['tables']);
        $this->assertStringContainsString('`balance_due` DECIMAL(10,2) NULL', $result['sql']);
        $this->assertStringContainsString('`variance` FLOAT NULL', $result['sql']);
        $this->assertStringNotContainsString('GENERATED ALWAYS AS ((`invoice_total` - `amount_paid`)) STORED', $result['sql']);
        $this->assertStringContainsString(
            'ALTER TABLE `customer_invoices` DROP COLUMN `balance_due`, ADD COLUMN `balance_due`',
            $result['sql'],
        );
        $this->assertStringContainsString(
            'ALTER TABLE `stock_take_lines` DROP COLUMN `variance`, ADD COLUMN `variance`',
            $result['sql'],
        );
        // INSERT values remain — allowed while column is temporarily non-generated.
        $this->assertStringContainsString(
            "INSERT INTO `customer_invoices` (`id`, `invoice_total`, `amount_paid`, `balance_due`) VALUES ('a',10.00,4.00,6.00);",
            $result['sql'],
        );
    }

    public function test_noop_when_no_generated_definitions(): void
    {
        $sql = "CREATE TABLE `users` (`id` int);\nINSERT INTO `users` VALUES (1);\n";
        $result = (new MysqlDumpGeneratedColumnSanitizer)->sanitizeSql($sql);

        $this->assertFalse($result['rewritten']);
        $this->assertSame($sql, $result['sql']);
    }
}
