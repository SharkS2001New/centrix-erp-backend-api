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
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.release_version', \App\Support\CentrixVersion::current());

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

    public function test_reshow_clears_dismissals_and_delete_removes_published_note(): void
    {
        Queue::fake();

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        $tenantAdmin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($tenantAdmin->organization_id);

        Sanctum::actingAs($superAdmin);
        $create = $this->postJson('/api/v1/admin/whats-new', [
            'title' => 'Updated till float',
            'body' => 'Till open now asks for float notes.',
            'audience' => 'all_users',
            'organization_ids' => [$org->id],
            'workspace_ids' => ['pos', 'backoffice'],
            'show_on_login' => true,
        ])->assertCreated();

        $noteId = (int) $create->json('data.id');
        $this->postJson("/api/v1/admin/whats-new/{$noteId}/publish")->assertOk();

        Sanctum::actingAs($tenantAdmin);
        $this->postJson("/api/v1/whats-new/{$noteId}/dismiss")->assertOk();
        $this->getJson('/api/v1/whats-new/pending?workspace=pos')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Sanctum::actingAs($superAdmin);
        $this->postJson("/api/v1/admin/whats-new/{$noteId}/reshow", ['renotify' => true])
            ->assertOk()
            ->assertJsonPath('cleared_reads', 1);

        Queue::assertPushed(\App\Jobs\PublishPlatformWhatsNewJob::class);

        Sanctum::actingAs($tenantAdmin);
        $this->getJson('/api/v1/whats-new/pending?workspace=pos')
            ->assertOk()
            ->assertJsonPath('data.0.id', $noteId);

        Sanctum::actingAs($superAdmin);
        $this->deleteJson("/api/v1/admin/whats-new/{$noteId}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNull(PlatformWhatsNewNote::query()->find($noteId));
        $this->assertSame(
            0,
            PlatformWhatsNewRead::query()->where('note_id', $noteId)->count(),
        );
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

    public function test_admin_can_list_users_who_dismissed_note(): void
    {
        Queue::fake();

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        $tenantAdmin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($tenantAdmin->organization_id);

        Sanctum::actingAs($superAdmin);
        $create = $this->postJson('/api/v1/admin/whats-new', [
            'title' => 'Seen-by tracking note',
            'body' => 'Track who dismissed this popup.',
            'audience' => 'all_users',
            'organization_ids' => [$org->id],
            'workspace_ids' => ['pos', 'backoffice'],
            'show_on_login' => true,
        ])->assertCreated();

        $noteId = (int) $create->json('data.id');
        $this->postJson("/api/v1/admin/whats-new/{$noteId}/publish")->assertOk();

        Sanctum::actingAs($superAdmin);
        $this->getJson("/api/v1/admin/whats-new/{$noteId}/reads")
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');

        Sanctum::actingAs($tenantAdmin);
        $this->postJson("/api/v1/whats-new/{$noteId}/dismiss")->assertOk();

        Sanctum::actingAs($superAdmin);
        $reads = $this->getJson("/api/v1/admin/whats-new/{$noteId}/reads")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.user_id', $tenantAdmin->id)
            ->assertJsonPath('data.0.organization_id', $org->id)
            ->json('data');

        $this->assertNotEmpty($reads[0]['dismissed_at'] ?? null);
        $this->assertSame($tenantAdmin->full_name, $reads[0]['full_name'] ?? null);

        $this->getJson('/api/v1/admin/whats-new')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $noteId,
                'reads_count' => 1,
            ]);
    }
}
