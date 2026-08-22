<?php

namespace Tests\Unit;

use App\Support\ShopDebtorsPermissions;
use Tests\TestCase;

class ShopDebtorsPermissionsTest extends TestCase
{
    public function test_permission_codes_per_bucket(): void
    {
        $this->assertSame(
            'shop_debtors.unpaid.view',
            ShopDebtorsPermissions::permissionCodeForBucket('unpaid'),
        );
        $this->assertSame(
            'shop_debtors.partial.view',
            ShopDebtorsPermissions::permissionCodeForBucket('partial'),
        );
        $this->assertSame(
            'shop_debtors.paid.view',
            ShopDebtorsPermissions::permissionCodeForBucket('paid'),
        );
    }

    public function test_registry_group_is_standalone_module(): void
    {
        $group = ShopDebtorsPermissions::registryGroup();
        $this->assertSame('Shop debtors', $group['label']);
        $this->assertSame('Unpaid shop debtors', $group['features']['unpaid']['label']);
        $this->assertSame('Partially paid shop debtors', $group['features']['partial']['label']);
        $this->assertSame('Paid shop debtors', $group['features']['paid']['label']);
    }
}
