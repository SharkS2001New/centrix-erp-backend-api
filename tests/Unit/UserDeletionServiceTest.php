<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Auth\UserDeletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class UserDeletionServiceTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_soft_deletes_when_in_app_notifications_exist(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $staff = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'unit_notify_user',
            'password' => Hash::make('password'),
            'full_name' => 'Unit Notify',
            'access_scope' => 'branch',
            'is_admin' => false,
            'is_active' => true,
        ]);

        DB::table('in_app_notifications')->insert([
            'user_id' => $staff->id,
            'organization_id' => $admin->organization_id,
            'type' => 'test',
            'title' => 'Hello',
            'message' => 'Test notification',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(UserDeletionService::class)->delete($staff, $admin);

        $this->assertSame('archived', $result['mode']);
        $this->assertSoftDeleted('users', ['id' => $staff->id]);
        $this->assertDatabaseHas('in_app_notifications', ['user_id' => $staff->id]);
    }

    public function test_permanently_deletes_when_no_retained_activity(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $staff = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'unit_clean_user',
            'password' => Hash::make('password'),
            'full_name' => 'Unit Clean',
            'access_scope' => 'branch',
            'is_admin' => false,
            'is_active' => true,
        ]);

        $result = app(UserDeletionService::class)->delete($staff, $admin);

        $this->assertSame('deleted', $result['mode']);
        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
    }
}
