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
        $this->assertSame($first['created'], $second['skipped']);
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
}
