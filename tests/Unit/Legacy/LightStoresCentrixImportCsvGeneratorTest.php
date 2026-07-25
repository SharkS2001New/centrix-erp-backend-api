<?php

namespace Tests\Unit\Legacy;

use App\Services\Legacy\LightStoresCentrixImportCsvGenerator;
use App\Services\Legacy\SqlDumpInsertParser;
use Tests\TestCase;

class LightStoresCentrixImportCsvGeneratorTest extends TestCase
{
    public function test_parser_reads_column_list_inserts_and_multiple_blocks(): void
    {
        $parser = new SqlDumpInsertParser;
        $sql = <<<'SQL'
INSERT INTO `product` (`id`, `product_code`, `product_name`) VALUES (1, 100, 'A');
INSERT INTO `product` VALUES (2, NULL, 3, 'MC', 101, 'B', 'b', 1, 1, 0, 0, 10, NULL, 5, 0, 0, NULL, 1, 1, 1, NULL, NULL, 0, 0, 0);
SQL;

        $this->assertSame(['product'], $parser->detectAllTableNames($sql));
        $rows = $parser->loadRows($sql, 'product');
        $this->assertCount(2, $rows);
        $this->assertSame(100, $rows[0][1]);
        $this->assertSame('B', $rows[1][5]);
    }

    public function test_products_csv_uses_lightstores_25_column_schema(): void
    {
        $sqlByTable = [
            'category' => "INSERT INTO `category` VALUES ('2020-01-01',NULL,10,'Food');\n",
            'sub_category' => "INSERT INTO `sub_category` VALUES ('2020-01-01',NULL,20,'Sugar','',10);\n",
            'uom' => "INSERT INTO `uom` VALUES ('2020-01-01',NULL,30,'KG','Kilogram');\n",
            'vat_status' => "INSERT INTO `vat_status` VALUES ('2020-01-01',NULL,40,'Vatable','V',16.00);\n",
            'suppliers' => "INSERT INTO `suppliers` VALUES (50,'Acme','a@x.com','0711','Jane','Addr','Town',1,NULL,NULL,NULL,NULL,'');\n",
            'product' => $this->sampleProductInsert(code: 9001, name: 'White Sugar', deleted: false),
        ];

        $generator = new LightStoresCentrixImportCsvGenerator($sqlByTable);
        $files = $generator->generateAll();

        $this->assertArrayHasKey('products-import.csv', $files);
        $this->assertArrayNotHasKey('reference-categories.csv', $files);
        $this->assertArrayNotHasKey('reference-products.csv', $files);

        $lines = preg_split("/\r\n|\n|\r/", trim($files['products-import.csv'])) ?: [];
        $this->assertGreaterThanOrEqual(2, count($lines));
        $this->assertStringContainsString('9001', $lines[1]);
        $this->assertStringContainsString('White Sugar', $lines[1]);
        $this->assertStringContainsString('Food', $lines[1]);
        $this->assertStringContainsString('Sugar', $lines[1]);
        $this->assertStringContainsString('Acme', $lines[1]);
        $this->assertStringContainsString('V', $lines[1]);
    }

    public function test_deleted_products_are_skipped(): void
    {
        $sqlByTable = [
            'category' => "INSERT INTO `category` VALUES ('2020-01-01',NULL,10,'Food');\n",
            'sub_category' => "INSERT INTO `sub_category` VALUES ('2020-01-01',NULL,20,'Sugar','',10);\n",
            'uom' => "INSERT INTO `uom` VALUES ('2020-01-01',NULL,30,'KG','Kilogram');\n",
            'vat_status' => "INSERT INTO `vat_status` VALUES ('2020-01-01',NULL,40,'Vatable','V',16.00);\n",
            'product' => $this->sampleProductInsert(code: 9002, name: 'Gone', deleted: true),
        ];

        $generator = new LightStoresCentrixImportCsvGenerator($sqlByTable);
        $files = $generator->generateAll();
        $lines = preg_split("/\r\n|\n|\r/", trim($files['products-import.csv'])) ?: [];
        $this->assertCount(1, $lines); // header only
    }

    public function test_single_dump_indexes_all_tables(): void
    {
        $sql = <<<SQL
INSERT INTO `category` VALUES ('2020-01-01',NULL,10,'Food');
INSERT INTO `sub_category` VALUES ('2020-01-01',NULL,20,'Sugar','',10);
INSERT INTO `uom` VALUES ('2020-01-01',NULL,30,'KG','Kilogram');
INSERT INTO `vat_status` VALUES ('2020-01-01',NULL,40,'Vatable','V',16.00);
{$this->sampleProductInsert(code: 9003, name: 'Dump Sugar', deleted: false)}
SQL;

        $parser = new SqlDumpInsertParser;
        $tables = $parser->detectAllTableNames($sql);
        $this->assertContains('product', $tables);
        $this->assertContains('category', $tables);

        $sqlByTable = [];
        foreach ($tables as $table) {
            $sqlByTable[$table] = $sql;
        }
        $generator = new LightStoresCentrixImportCsvGenerator($sqlByTable, $parser);
        $files = $generator->generateAll();
        $this->assertStringContainsString('Dump Sugar', $files['products-import.csv']);
        $this->assertStringContainsString('Food', $files['categories-import.csv']);
    }

    private function sampleProductInsert(int $code, string $name, bool $deleted): string
    {
        $dlt = $deleted ? "'2024-01-01 00:00:00'" : 'NULL';

        // 25 columns matching LightStores schema.sql `product`
        return "INSERT INTO `product` VALUES ("
            ."'2020-01-01 00:00:00',NULL,1,'MAIN',{$code},'{$name}','search',"
            .'20,30,5,2,120,50,80,0,10,1.5,1,40,1,'
            ."{$dlt},NULL,1,0,0);\n";
    }
}
