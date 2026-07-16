<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\InventoryTransaction;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Support\OrganizationIdResolver;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class OrganizationScopedOperationalRecordsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_inventory_transactions_and_expenses_stamp_organization_id(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $product = Product::query()
            ->where('organization_id', $admin->organization_id)
            ->firstOrFail();

        $handler = new class
        {
            use \App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;

            public function post(array $data)
            {
                return $this->postStockLedger($data, allowBelowStock: true);
            }
        };

        $txn = $handler->post([
            'branch_id' => (int) $admin->branch_id,
            'product_code' => (string) $product->product_code,
            'stock_location' => 'shop',
            'transaction_type' => 'ADJUSTMENT',
            'quantity_change' => 1,
            'created_by' => $admin->id,
        ]);

        $this->assertSame((int) $admin->organization_id, (int) $txn->organization_id);
        $this->assertDatabaseHas('inventory_transactions', [
            'id' => $txn->id,
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
        ]);

        $expense = Expense::create([
            'organization_id' => OrganizationIdResolver::requireForBranch((int) $admin->branch_id),
            'branch_id' => $admin->branch_id,
            'expense_group_id' => DB::table('expense_groups')->value('id') ?? 1,
            'description' => 'Org scope test',
            'expense_amount' => 10,
            'expense_date' => now()->toDateString(),
            'payment_method_id' => DB::table('payment_methods')->value('id') ?? 1,
            'recorded_by' => $admin->id,
        ]);

        $this->assertSame((int) $admin->organization_id, (int) $expense->organization_id);
    }

    public function test_product_relation_is_organization_scoped_for_inventory_transactions(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $orgA = (int) $admin->organization_id;
        $branchA = (int) $admin->branch_id;

        $orgB = Organization::create([
            'company_code' => 'ORGPROD2',
            'org_name' => 'Org Product Two',
            'org_email' => 'orgprod2@test.com',
            'primary_tel' => '0700333001',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
        ]);
        $branchB = Branch::create([
            'organization_id' => $orgB->id,
            'branch_code' => 'ORGPROD2-MAIN',
            'branch_name' => 'Org B Main',
            'is_active' => true,
        ]);

        $sharedCode = 'SHARED-ORG-SCOPE-'.uniqid();
        $template = Product::query()->where('organization_id', $orgA)->firstOrFail();

        Product::query()->create([
            'organization_id' => $orgA,
            'product_code' => $sharedCode,
            'product_name' => 'Org A Product',
            'subcategory_id' => $template->subcategory_id,
            'unit_id' => $template->unit_id,
            'unit_price' => 10,
            'discount_percentage' => 0,
            'vat_id' => $template->vat_id,
            'stock_in_shop' => 0,
            'stock_in_store' => 0,
        ]);
        Product::query()->create([
            'organization_id' => $orgB->id,
            'product_code' => $sharedCode,
            'product_name' => 'Org B Product',
            'subcategory_id' => $template->subcategory_id,
            'unit_id' => $template->unit_id,
            'unit_price' => 10,
            'discount_percentage' => 0,
            'vat_id' => $template->vat_id,
            'stock_in_shop' => 0,
            'stock_in_store' => 0,
        ]);

        $txn = InventoryTransaction::create([
            'organization_id' => $orgB->id,
            'branch_id' => $branchB->id,
            'product_code' => $sharedCode,
            'stock_location' => 'shop',
            'transaction_type' => 'ADJUSTMENT',
            'quantity_change' => 1,
            'quantity_before' => 0,
            'quantity_after' => 1,
            'created_by' => $admin->id,
        ]);

        $txn->load('product');
        $this->assertNotNull($txn->product);
        $this->assertSame((int) $orgB->id, (int) $txn->product->organization_id);
        $this->assertSame('Org B Product', $txn->product->product_name);
        $this->assertNotSame($branchA, (int) $branchB->id);
    }
}
