<?php

namespace Tests\Unit;

use App\Services\Erp\BackofficeSections;
use PHPUnit\Framework\TestCase;

class BackofficeSectionsTest extends TestCase
{
    public function test_missing_map_leaves_every_section_on(): void
    {
        $sections = BackofficeSections::normalize(null);

        $this->assertTrue($sections['customers']);
        $this->assertTrue($sections['suppliers']);
        $this->assertTrue($sections['sales_orders']);
    }

    public function test_explicit_false_turns_one_section_off(): void
    {
        $sections = BackofficeSections::normalize([
            'suppliers' => false,
            'customers' => true,
        ]);

        $this->assertFalse($sections['suppliers']);
        $this->assertTrue($sections['customers']);
        $this->assertTrue($sections['products']);
    }

    public function test_purchase_permissions_belong_to_suppliers_section(): void
    {
        $this->assertSame('suppliers', BackofficeSections::sectionForPermissionCode('purchasing.lpo.view'));
        $this->assertSame('customers', BackofficeSections::sectionForPermissionCode('customers.customers.view'));
        $this->assertSame('sales_orders', BackofficeSections::sectionForPermissionCode('sales.order_queue_paid.view'));
        $this->assertNull(BackofficeSections::sectionForPermissionCode('pos.terminal.view'));
    }

    public function test_disabled_section_drops_its_features_only(): void
    {
        $modules = BackofficeSections::filterApplicationModules([
            [
                'module' => 'purchasing',
                'features' => [
                    ['key' => 'suppliers'],
                    ['key' => 'lpo'],
                ],
            ],
            [
                'module' => 'customers',
                'features' => [
                    ['key' => 'customers'],
                ],
            ],
        ], BackofficeSections::normalize(['suppliers' => false]));

        $this->assertCount(1, $modules);
        $this->assertSame('customers', $modules[0]['module']);
    }
}
