<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class UserAccountGuardTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_user_cannot_delete_their_own_account(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/users/{$admin->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user']);
    }

    public function test_user_cannot_disable_their_own_login(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/users/{$admin->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);
    }

    public function test_organization_administrator_cannot_be_deleted(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $otherAdmin = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'second_admin',
            'password' => Hash::make('password'),
            'full_name' => 'Second Admin',
            'access_scope' => 'org',
            'is_admin' => true,
            'is_active' => true,
        ]);

        $this->deleteJson("/api/v1/users/{$otherAdmin->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user']);
    }

    public function test_organization_administrator_login_cannot_be_disabled(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $role = Role::where('role_name', 'Cashier')->firstOrFail();

        $staff = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'regular_staff',
            'password' => Hash::make('password'),
            'full_name' => 'Regular Staff',
            'access_scope' => 'branch',
            'is_admin' => false,
            'is_active' => true,
        ]);

        $this->putJson("/api/v1/users/{$admin->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);

        $this->putJson("/api/v1/users/{$staff->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);
    }

    public function test_regular_user_without_activity_is_deleted_permanently(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $role = Role::where('role_name', 'Cashier')->firstOrFail();

        $staff = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'delete_me',
            'password' => Hash::make('password'),
            'full_name' => 'Delete Me',
            'access_scope' => 'branch',
            'is_admin' => false,
            'is_active' => true,
        ]);

        $this->deleteJson("/api/v1/users/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('mode', 'deleted')
            ->assertJsonPath('message', 'User permanently deleted.');

        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
    }

    public function test_regular_user_with_sales_activity_is_archived(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $role = Role::where('role_name', 'Cashier')->firstOrFail();

        $staff = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'archive_me',
            'password' => Hash::make('password'),
            'full_name' => 'Archive Me',
            'access_scope' => 'branch',
            'is_admin' => false,
            'is_active' => true,
        ]);

        \Illuminate\Support\Facades\DB::table('sales')->insert([
            'order_num' => 999001,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'pos',
            'payment_status' => 'paid',
            'amount_paid' => 100,
            'cashier_id' => $staff->id,
            'status' => 'paid',
            'total_vat' => 0,
            'order_total' => 100,
            'order_discount' => 0,
            'voucher_payment_amount' => 0,
            'points_payment_amount' => 0,
            'cash' => 100,
            'order_change' => 0,
            'payment_method_code' => 'CASH',
            'is_credit_sale' => 0,
            'stock_balanced' => 0,
            'receipt_printed' => 0,
            'archived' => 0,
        ]);

        $this->deleteJson("/api/v1/users/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('mode', 'archived')
            ->assertJsonPath(
                'message',
                'User archived (soft deleted). Related records are retained so the account cannot be permanently removed.',
            );

        $this->assertSoftDeleted('users', ['id' => $staff->id]);
    }

    public function test_user_with_in_app_notifications_is_soft_deleted_not_hard_deleted(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $role = Role::where('role_name', 'Cashier')->firstOrFail();

        $staff = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'notify_me',
            'password' => Hash::make('password'),
            'full_name' => 'Notify Me',
            'access_scope' => 'branch',
            'is_admin' => false,
            'is_active' => true,
        ]);

        \Illuminate\Support\Facades\DB::table('in_app_notifications')->insert([
            'user_id' => $staff->id,
            'organization_id' => $admin->organization_id,
            'type' => 'test',
            'title' => 'Hello',
            'message' => 'Test notification',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/v1/users/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('mode', 'archived');

        $this->assertSoftDeleted('users', ['id' => $staff->id]);
        $this->assertDatabaseHas('in_app_notifications', ['user_id' => $staff->id]);
    }
}
