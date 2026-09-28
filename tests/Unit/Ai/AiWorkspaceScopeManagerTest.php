<?php

namespace Tests\Unit\Ai;

use App\Models\User;
use App\Services\Ai\AiWorkspaceScope;
use App\Services\Erp\ErpContext;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiWorkspaceScopeManagerTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_manager_pathname_resolves_and_keeps_full_navigation(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $gate = app(ErpContext::class)->gateForUser($user);
        $scopeService = app(AiWorkspaceScope::class);

        $scope = $scopeService->resolve($user, $gate, null, '/manager');

        $this->assertSame('manager', $scope['id']);
        $this->assertSame([], $scope['nav_path_prefixes']);
        $this->assertTrue($scopeService->isMessageInScope('How do I correct invoice S1537?', $scope));

        $sections = [
            [
                'id' => 'sales',
                'items' => [
                    ['label' => 'Orders', 'path' => '/sales/orders'],
                ],
            ],
            [
                'id' => 'hr',
                'items' => [
                    ['label' => 'Employees', 'path' => '/hr/employees'],
                ],
            ],
        ];

        $filtered = $scopeService->filterNavigation($sections, $scope);
        $this->assertCount(2, $filtered);
        $this->assertSame('/sales/orders', $filtered[0]['items'][0]['path']);
        $this->assertSame('/hr/employees', $filtered[1]['items'][0]['path']);
    }
}
