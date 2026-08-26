<?php

namespace Tests\Unit\Ai;

use App\Models\Organization;
use App\Models\RouteModel;
use App\Models\User;
use App\Services\Ai\AiToolRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class GetUserDetailsToolTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_get_user_details_returns_assigned_routes(): void
    {
        if (! Schema::hasTable('user_assigned_routes') || ! Schema::hasTable('routes')) {
            $this->markTestSkipped('user_assigned_routes / routes tables missing');
        }

        $admin = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $admin->organization_id;

        $route = RouteModel::query()->create([
            'organization_id' => $orgId,
            'branch_id' => $admin->branch_id,
            'route_name' => 'Chege Test Route',
            'is_active' => true,
        ]);

        $user = User::create([
            'organization_id' => $orgId,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'chege_route_test',
            'password' => Hash::make('password'),
            'full_name' => 'Chege Route Test',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        DB::table('user_assigned_routes')->insert([
            'user_id' => $user->id,
            'route_id' => $route->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(AiToolRegistry::class)->execute('get_user_details', $admin, [
            'user_name' => 'Chege Route Test',
        ]);

        $this->assertFalse($result['error'] ?? false);
        $this->assertSame('CHEGE_ROUTE_TEST', strtoupper((string) ($result['user']['username'] ?? '')));
        $routeNames = collect($result['user']['assigned_routes'] ?? [])->pluck('route_name')->all();
        $this->assertContains('Chege Test Route', $routeNames);
    }

    public function test_get_route_details_lists_assigned_users(): void
    {
        if (! Schema::hasTable('user_assigned_routes') || ! Schema::hasTable('routes')) {
            $this->markTestSkipped('user_assigned_routes / routes tables missing');
        }

        $admin = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $admin->organization_id;

        $route = RouteModel::query()->create([
            'organization_id' => $orgId,
            'branch_id' => $admin->branch_id,
            'route_name' => 'Westlands Ops Route',
            'is_active' => true,
        ]);

        $user = User::create([
            'organization_id' => $orgId,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'westlands_rep',
            'password' => Hash::make('password'),
            'full_name' => 'Westlands Rep',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        DB::table('user_assigned_routes')->insert([
            'user_id' => $user->id,
            'route_id' => $route->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(AiToolRegistry::class)->execute('get_route_details', $admin, [
            'route_name' => 'Westlands Ops Route',
        ]);

        $this->assertFalse($result['error'] ?? false);
        $usernames = collect($result['route']['assigned_users'] ?? [])
            ->pluck('username')
            ->map(fn ($u) => strtoupper((string) $u))
            ->all();
        $this->assertContains('WESTLANDS_REP', $usernames);
    }
}
