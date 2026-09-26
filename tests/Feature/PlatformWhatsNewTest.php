<?php

namespace Tests\Feature;

use App\Models\InAppNotification;
use App\Models\Organization;
use App\Models\PlatformWhatsNewNote;
use App\Models\PlatformWhatsNewRead;
use App\Models\User;
use App\Services\Platform\PlatformWhatsNewService;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformWhatsNewTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_create_publish_and_target_workspace(): void
    {
        Queue::fake();

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        $tenantAdmin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($tenantAdmin->organization_id);

        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/whats-new', [
            'title' => 'POS receipt tweak',
            'body' => 'Receipts now show the branch phone number.',
            'link_url' => 'https://docs.example.com/pos',
            'audience' => 'all_users',
            'organization_ids' => [$org->id],
            'workspace_ids' => ['pos'],
            'show_on_login' => true,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.workspace_ids.0', 'pos');

        $noteId = (int) $create->json('data.id');

        $this->postJson("/api/v1/admin/whats-new/{$noteId}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        Queue::assertPushed(\App\Jobs\PublishPlatformWhatsNewJob::class);

        // Run fan-out synchronously for assertions.
        $note = PlatformWhatsNewNote::query()->findOrFail($noteId);
        $count = app(PlatformWhatsNewService::class)->fanOutNotifications($note);
        $this->assertGreaterThan(0, $count);

        $this->assertTrue(
            InAppNotification::query()
                ->where('type', 'whats_new')
                ->where('organization_id', $org->id)
                ->where('title', 'like', '%POS receipt tweak%')
                ->exists()
        );

        // Accounting-only note must not appear for POS cashiers without accounting.
        Sanctum::actingAs($tenantAdmin);
        $this->getJson('/api/v1/whats-new/pending?workspace=pos')
            ->assertOk()
            ->assertJsonPath('data.0.id', $noteId);

        $this->postJson("/api/v1/whats-new/{$noteId}/dismiss")
            ->assertOk();

        $this->assertTrue(
            PlatformWhatsNewRead::query()
                ->where('note_id', $noteId)
                ->where('user_id', $tenantAdmin->id)
                ->exists()
        );

        $this->getJson('/api/v1/whats-new/pending?workspace=pos')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_admins_only_audience_skips_non_admins(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        $tenantAdmin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($tenantAdmin->organization_id);

        $cashier = User::query()
            ->where('organization_id', $org->id)
            ->where('is_admin', false)
            ->where('is_super_admin', false)
            ->where('is_active', true)
            ->first();

        if (! $cashier) {
            $this->markTestSkipped('No non-admin user in demo seed.');
        }

        Sanctum::actingAs($superAdmin);
        $create = $this->postJson('/api/v1/admin/whats-new', [
            'title' => 'Admin settings change',
            'body' => 'Role matrix labels updated.',
            'audience' => 'admins_only',
            'organization_ids' => [$org->id],
            'workspace_ids' => ['admin', 'backoffice'],
            'show_on_login' => true,
        ])->assertCreated();

        $noteId = (int) $create->json('data.id');
        $this->postJson("/api/v1/admin/whats-new/{$noteId}/publish")->assertOk();

        $note = PlatformWhatsNewNote::query()->findOrFail($noteId);
        app(PlatformWhatsNewService::class)->fanOutNotifications($note);

        Sanctum::actingAs($tenantAdmin);
        $this->getJson('/api/v1/whats-new/pending')
            ->assertOk()
            ->assertJsonFragment(['id' => $noteId]);

        Sanctum::actingAs($cashier);
        $pending = $this->getJson('/api/v1/whats-new/pending')->assertOk()->json('data');
        $this->assertFalse(collect($pending)->contains(fn ($row) => (int) ($row['id'] ?? 0) === $noteId));
    }

    public function test_workspace_mismatch_hides_login_modal(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        $tenantAdmin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($tenantAdmin->organization_id);

        Sanctum::actingAs($superAdmin);
        $create = $this->postJson('/api/v1/admin/whats-new', [
            'title' => 'HR leave calendar',
            'body' => 'Leave requests now show remaining balance.',
            'audience' => 'all_users',
            'organization_ids' => [$org->id],
            'workspace_ids' => ['hr'],
            'show_on_login' => true,
        ])->assertCreated();

        $noteId = (int) $create->json('data.id');
        $this->postJson("/api/v1/admin/whats-new/{$noteId}/publish")->assertOk();

        Sanctum::actingAs($tenantAdmin);
        // Asking for backoffice should not surface an HR-only note.
        $pending = $this->getJson('/api/v1/whats-new/pending?workspace=backoffice')
            ->assertOk()
            ->json('data');

        $this->assertFalse(collect($pending)->contains(fn ($row) => (int) ($row['id'] ?? 0) === $noteId));
    }
}
