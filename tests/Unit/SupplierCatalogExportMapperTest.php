<?php

namespace Tests\Unit;

use App\Services\Background\SupplierCatalogExportMapper;
use Tests\TestCase;

class SupplierCatalogExportMapperTest extends TestCase
{
    public function test_maps_supplier_fields_for_export(): void
    {
        $mapper = new SupplierCatalogExportMapper();
        $mapped = $mapper->mapBatch([
            [
                'supplier_code' => 'SUP-001',
                'supplier_name' => 'Acme Supplies',
                'contact_person' => 'Jane',
                'phone' => '0712345678',
                'current_balance' => 1200.5,
                'contacts' => [
                    ['label' => 'Warehouse', 'phone' => '0700000000', 'email' => 'wh@example.com'],
                ],
                'is_active' => true,
            ],
        ]);

        $row = $mapped[0];
        $this->assertSame('SUP-001', $row['supplier_code']);
        $this->assertSame('Acme Supplies', $row['supplier_name']);
        $this->assertSame(1200.5, $row['current_balance']);
        $this->assertStringContainsString('Warehouse', $row['other_contacts']);
        $this->assertSame('Yes', $row['is_active']);
    }
}
