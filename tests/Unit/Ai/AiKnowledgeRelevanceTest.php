<?php

namespace Tests\Unit\Ai;

use App\Models\AiKnowledgeEntry;
use App\Models\User;
use App\Services\Ai\AiKnowledgeService;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiKnowledgeRelevanceTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_search_relevant_ranks_matching_notes_above_unrelated(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $service = app(AiKnowledgeService::class);

        $service->teachGlobal($user, 'Unrelated payroll tip', 'NSSF rates change yearly.', '/hr', 'hr');
        $service->teachGlobal(
            $user,
            'Q: Is stock in kg or bags?',
            'A: Stock is stored in base units from the product UoM. Bags vs kg come from conversion_factor. Use get_product_details.',
            '/uoms',
            'backoffice',
        );

        $hits = $service->searchRelevant('is sugar sold in kg or bags?', 5);

        $this->assertNotEmpty($hits);
        $this->assertSame('exemplar', $hits[0]['usage'] ?? null);
        $this->assertArrayHasKey('sample_answer_style', $hits[0]);
        $this->assertStringContainsStringIgnoringCase('kg', (string) ($hits[0]['topic'].' '.$hits[0]['content']));
    }

    public function test_install_foundation_notes_is_idempotent(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $service = app(AiKnowledgeService::class);

        $first = $service->installFoundationNotes($user);
        $second = $service->installFoundationNotes($user);

        $this->assertGreaterThan(0, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame($first['total_in_config'], $second['skipped']);
        $this->assertSame($first['created'] + $first['skipped'], $first['total_in_config']);
        $this->assertGreaterThan(
            0,
            AiKnowledgeEntry::query()->whereNull('organization_id')->where('source', 'foundation_seed')->count(),
        );
    }

    public function test_bulk_import_accepts_question_answer_keys(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $service = app(AiKnowledgeService::class);

        $result = $service->teachGlobalBulk($user, [
            [
                'question' => 'Where is GRN?',
                'answer' => 'Open /inventory/receipts to receive goods.',
                'path' => '/inventory/receipts',
            ],
        ]);

        $this->assertSame(1, $result['created']);
        $this->assertSame('Where is GRN?', $result['entries'][0]['topic']);
    }

    public function test_find_duplicate_clusters_and_merge(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $service = app(AiKnowledgeService::class);

        $keep = $service->teachGlobal($user, 'Where is GRN?', 'Open /inventory/receipts.');
        $dup = $service->teachGlobal($user, 'Where is GRN', 'Goods received at /inventory/receipts.');
        $service->teachGlobal($user, 'How to add a product', 'Use /inventory/products.');

        $scan = $service->findDuplicateClusters(null, 85.0);
        $this->assertSame(1, $scan['cluster_count']);
        $this->assertSame(1, $scan['duplicate_entry_count']);

        $merged = $service->mergeGlobal($user, (int) $keep['id'], [(int) $dup['id']]);
        $this->assertNotNull($merged);
        $this->assertDatabaseMissing('ai_knowledge_entries', ['id' => $dup['id']]);
        $this->assertStringContainsString('/inventory/receipts', (string) $merged['content']);
    }

    public function test_manager_scope_uses_same_platform_training_as_unscoped(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $service = app(AiKnowledgeService::class);

        $service->teachGlobal(
            $user,
            'Q: How do I correct a wrongly paid invoice?',
            'A: Look up the order with get_order_details, then void the wrong payment and re-collect.',
            '/sales/orders',
            'backoffice',
        );
        $service->teachGlobal(
            $user,
            'Q: How do I run payroll?',
            'A: Open /hr/payroll and process approved overtime only.',
            '/hr/payroll',
            'hr',
        );

        $this->assertNull($service->normalizeKnowledgeWorkspaceId('manager'));
        $this->assertSame('backoffice', $service->normalizeKnowledgeWorkspaceId('backoffice'));

        $managerHits = $service->searchRelevant('wrongly paid invoice S1537', 10, 'manager');
        $unscopedHits = $service->searchRelevant('wrongly paid invoice S1537', 10, null);
        $backofficeOnly = $service->searchRelevant('wrongly paid invoice S1537', 10, 'backoffice');

        $this->assertNotEmpty($managerHits);
        $this->assertStringContainsStringIgnoringCase(
            'invoice',
            (string) ($managerHits[0]['topic'].' '.$managerHits[0]['content']),
        );
        $this->assertSame(
            collect($unscopedHits)->pluck('id')->sort()->values()->all(),
            collect($managerHits)->pluck('id')->sort()->values()->all(),
        );
        $this->assertTrue(
            collect($backofficeOnly)->contains(fn ($row) => str_contains(strtolower((string) ($row['topic'] ?? '')), 'invoice')),
        );

        // Manager must not drop HR-tagged training the way a backoffice-only filter would.
        $payrollHits = $service->searchRelevant('how do I run payroll?', 10, 'manager');
        $this->assertTrue(
            collect($payrollHits)->contains(fn ($row) => str_contains(strtolower((string) ($row['topic'] ?? '')), 'payroll')),
        );
        $this->assertFalse(
            collect($service->searchRelevant('how do I run payroll?', 10, 'backoffice'))
                ->contains(fn ($row) => str_contains(strtolower((string) ($row['topic'] ?? '')), 'payroll')),
        );
    }

    public function test_install_foundation_reports_total_in_config(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $service = app(AiKnowledgeService::class);

        $result = $service->installFoundationNotes($user);

        $this->assertArrayHasKey('total_in_config', $result);
        $this->assertGreaterThan(0, $result['total_in_config']);
        $this->assertSame($result['created'] + $result['skipped'], $result['total_in_config']);
    }

    public function test_editing_foundation_seed_marks_source_as_platform_training(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $service = app(AiKnowledgeService::class);
        $service->installFoundationNotes($user);

        $entry = AiKnowledgeEntry::query()
            ->whereNull('organization_id')
            ->where('source', 'foundation_seed')
            ->first();
        $this->assertNotNull($entry);

        $updated = $service->updateGlobal($user, (int) $entry->id, [
            'content' => $entry->content.' (edited for tenant clarity)',
        ]);

        $this->assertSame('platform_training', $updated['source'] ?? null);
        $this->assertDatabaseHas('ai_knowledge_entries', [
            'id' => $entry->id,
            'source' => 'platform_training',
        ]);
    }
}
