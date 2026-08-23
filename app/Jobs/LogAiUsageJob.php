<?php

namespace App\Jobs;

use App\Models\AiUsageLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Persist AI usage asynchronously so chat latency is not blocked by logging.
 */
class LogAiUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(): void
    {
        if (! Schema::hasTable('ai_usage_logs')) {
            return;
        }

        try {
            $data = $this->payload;
            if (! empty($data['error_message'])) {
                $data['error_message'] = Str::limit((string) $data['error_message'], 500);
            }
            if (isset($data['prompt_preview'])) {
                $data['prompt_preview'] = Str::limit((string) $data['prompt_preview'], 2000, '');
            }
            if (isset($data['response_preview'])) {
                $data['response_preview'] = Str::limit((string) $data['response_preview'], 2000, '');
            }

            // Only persist columns that exist (migration may lag behind deploy).
            $data = collect($data)
                ->filter(fn ($_, $key) => Schema::hasColumn('ai_usage_logs', (string) $key))
                ->all();

            if ($data === []) {
                return;
            }

            AiUsageLog::query()->create($data);
        } catch (\Throwable $e) {
            Log::warning('Failed to write AI usage log (async)', ['message' => $e->getMessage()]);
            throw $e;
        }
    }
}
