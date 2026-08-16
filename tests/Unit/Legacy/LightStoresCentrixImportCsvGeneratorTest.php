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
        $this->assertArrayHasKey('reference-categories.csv', $files);

        $lines = preg_split("/\r\n|\n|\r/", trim($files['products-import.csv'])) ?: [];
        $this->assertGreaterThanOrEqual(2, count($lines));
        $this->assertStringContainsString('9001', $lines[1]);
        $this->assertStringContainsString('White Sugar', $lines[1]);
        $this->assertStringContainsString('Food', $lines[1]);
        $this->assertStringContainsString('Sugar', $lines[1]);
        $this->assertStringContainsString('Acme', $lines[1]);
        $this->assertStringContainsString('V', $lines[1]);
        $this->assertStringContainsString('sell_on_retail', $lines[0]);
        $this->assertStringNotContainsString('sell_on_bar', $lines[0]);
    }

    public function test_hospitality_products_include_menu_channels_and_skip_retail_files(): void
    {
        $sqlByTable = [
            'category' => "INSERT INTO `category` VALUES ('2020-01-01',NULL,10,'Drinks');\n",
            'sub_category' => "INSERT INTO `sub_category` VALUES ('2020-01-01',NULL,20,'Beer','',10);\n",
            'uom' => "INSERT INTO `uom` VALUES ('2020-01-01',NULL,30,'BOTTLE','Bottle');\n",
            'vat_status' => "INSERT INTO `vat_status` VALUES ('2020-01-01',NULL,40,'Vatable','V',16.00);\n",
            'product' => $this->sampleProductInsert(code: 9101, name: 'Tusker Lager', deleted: false),
        ];

        $generator = new LightStoresCentrixImportCsvGenerator(
            $sqlByTable,
            null,
            LightStoresCentrixImportCsvGenerator::TARGET_HOSPITALITY,
        );
        $files = $generator->generateAll();

        $this->assertArrayHasKey('products-import.csv', $files);
        $this->assertArrayNotHasKey('routes-import.csv', $files);
        $this->assertArrayNotHasKey('customers-import.csv', $files);
        $this->assertArrayNotHasKey('retail-packages-import.csv', $files);

        $lines = preg_split("/\r\n|\n|\r/", trim($files['products-import.csv'])) ?: [];
        $this->assertGreaterThanOrEqual(2, count($lines));
        $this->assertStringContainsString('sell_on_bar', $lines[0]);
        $this->assertStringContainsString('sell_on_hotel', $lines[0]);
        $this->assertStringContainsString('Tusker Lager', $lines[1]);
        // Drink category → bar only
        $this->assertMatchesRegularExpression('/false,true,false\s*$/', $lines[1]);
        $this->assertStringContainsString('Hotel Menu Catalogue', $files['README.md']);
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

    public function test_parser_reads_unquoted_tables_and_semicolons_inside_strings(): void
    {
        $parser = new SqlDumpInsertParser;
        $sql = <<<'SQL'
INSERT INTO product VALUES (1,'MAIN',9001,'Sugar; white',NULL);
INSERT INTO `product` VALUES (2,'MAIN',9002,'O''Brien Tea',NULL);
SQL;

        $this->assertSame(['product'], $parser->detectAllTableNames($sql));
        $rows = $parser->loadRows($sql, 'product');
        $this->assertCount(2, $rows);
        $this->assertSame('Sugar; white', $rows[0][3]);
        $this->assertSame("O'Brien Tea", $rows[1][3]);
    }

    public function test_products_csv_maps_named_columns_and_keeps_rows_without_subcategory(): void
    {
        $sqlByTable = [
            'category' => "INSERT INTO `category` VALUES ('2020-01-01',NULL,10,'Food');\n",
            'sub_category' => "INSERT INTO `sub_category` VALUES ('2020-01-01',NULL,20,'Sugar','',10);\n",
            'uom' => "INSERT INTO `uom` VALUES ('2020-01-01',NULL,30,'KG','Kilogram');\n",
            'vat_status' => "INSERT INTO `vat_status` VALUES ('2020-01-01',NULL,40,'Vatable','V',16.00);\n",
            'product' => "INSERT INTO `product` (`id`, `product_code`, `product_name`, `subcateg_id`, `unit_id`, `unit_price`, `dlt_on`) "
                ."VALUES (1, 9401, 'Loose Salt', NULL, NULL, 55.5, NULL),\n"
                ."(2, 9402, 'Sugar; icing', 20, 30, 80, NULL);\n",
        ];

        $generator = new LightStoresCentrixImportCsvGenerator($sqlByTable);
        $files = $generator->generateAll();
        $lines = preg_split("/\r\n|\n|\r/", trim($files['products-import.csv'])) ?: [];

        $this->assertCount(3, $lines);
        $this->assertStringContainsString('9401', $lines[1]);
        $this->assertStringContainsString('Loose Salt', $lines[1]);
        $this->assertStringContainsString('55.5', $lines[1]);
        $this->assertStringContainsString('9402', $lines[2]);
        $this->assertStringContainsString('Sugar; icing', $lines[2]);
        $this->assertStringContainsString('Food', $lines[2]);
    }

    public function test_products_csv_keeps_rows_when_extra_columns_follow_classic_schema(): void
    {
        $extra = str_repeat(',0', 8);
        $sqlByTable = [
            'category' => "INSERT INTO `category` VALUES ('2020-01-01',NULL,10,'Food');\n",
            'sub_category' => "INSERT INTO `sub_category` VALUES ('2020-01-01',NULL,20,'Sugar','',10);\n",
            'uom' => "INSERT INTO `uom` VALUES ('2020-01-01',NULL,30,'KG','Kilogram');\n",
            'vat_status' => "INSERT INTO `vat_status` VALUES ('2020-01-01',NULL,40,'Vatable','V',16.00);\n",
            'product' => "INSERT INTO `product` VALUES ("
                ."'2020-01-01 00:00:00',NULL,1,'MAIN',9501,'Brown Sugar','search',"
                .'20,30,5,2,120,50,80,0,10,1.5,1,40,1,'
                ."NULL,NULL,1,0,0,'2024-06-01 00:00:00'{$extra});\n",
        ];

        $generator = new LightStoresCentrixImportCsvGenerator($sqlByTable);
        $files = $generator->generateAll();
        $lines = preg_split("/\r\n|\n|\r/", trim($files['products-import.csv'])) ?: [];

        $this->assertGreaterThanOrEqual(2, count($lines));
        $this->assertStringContainsString('9501', $lines[1]);
        $this->assertStringContainsString('Brown Sugar', $lines[1]);
    }

    public function test_products_table_alias_and_unquoted_insert(): void
    {
        $sqlByTable = [
            'products' => "INSERT INTO products (`product_code`, `product_name`, `unit_price`, `dlt_on`) "
                ."VALUES (9601, 'Cooking Oil', 210, NULL);\n",
        ];

        $generator = new LightStoresCentrixImportCsvGenerator($sqlByTable);
        $files = $generator->generateAll();
        $this->assertStringContainsString('9601', $files['products-import.csv']);
        $this->assertStringContainsString('Cooking Oil', $files['products-import.csv']);
        $this->assertStringContainsString('210', $files['products-import.csv']);
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
