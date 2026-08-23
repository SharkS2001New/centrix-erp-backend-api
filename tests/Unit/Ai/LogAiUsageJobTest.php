<?php

namespace Tests\Unit\Ai;

use App\Jobs\LogAiUsageJob;
use App\Models\AiUsageLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class LogAiUsageJobTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_job_persists_usage_row(): void
    {
        if (! Schema::hasTable('ai_usage_logs')) {
            $this->markTestSkipped('ai_usage_logs table missing');
        }

        $user = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($user->organization_id);

        (new LogAiUsageJob([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'provider' => 'ollama',
            'model' => 'llama3.2',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'estimated_cost' => 0,
            'status' => 'success',
            'latency_ms' => 120,
            'prompt_preview' => 'What were sales today?',
            'response_preview' => 'Sales were KES 0.',
            'worker' => 'test-worker',
        ]))->handle();

        $row = AiUsageLog::query()->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('ollama', $row->provider);
        $this->assertSame('llama3.2', $row->model);
        $this->assertSame('success', $row->status);
        $this->assertEquals(0, (float) $row->estimated_cost);
    }
}
