<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\PlatformMarketingPortfolio;
use App\Services\Background\BackgroundTaskService;
use App\Services\Platform\PlatformMarketingPortfolioService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateMarketingPortfolioJob implements ShouldQueue
{
    use Queueable;
    use RunsBackgroundTaskOnce;

    /** Full Gemini/OpenAI portfolio copy can take well over a minute. */
    public int $timeout = 180;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(
        BackgroundTaskService $tasks,
        PlatformMarketingPortfolioService $portfolios,
    ): void {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($this->shouldSkipBackgroundTask($task) || ! $tasks->markRunning($task)) {
            return;
        }

        try {
            $payload = $task->payload ?? [];
            $portfolioId = (int) ($payload['portfolio_id'] ?? 0);
            $mode = (string) ($payload['mode'] ?? 'full');
            $instruction = isset($payload['instruction']) ? (string) $payload['instruction'] : null;
            if ($instruction !== null && trim($instruction) === '') {
                $instruction = null;
            }

            $portfolio = PlatformMarketingPortfolio::query()->find($portfolioId);
            if ($portfolio === null) {
                throw new \RuntimeException('Marketing portfolio not found.');
            }

            $tasks->updateProgress($task, 15, 'Writing portfolio with AI…');

            if ($mode === 'section') {
                $sectionKey = (string) ($payload['section_key'] ?? '');
                if ($sectionKey === '') {
                    throw new \RuntimeException('Section key is required.');
                }
                $portfolio = $portfolios->regenerateSection($portfolio, $sectionKey, $instruction);
                $message = 'Section updated.';
            } else {
                $portfolio = $portfolios->generate($portfolio, $instruction);
                $message = 'Portfolio generated.';
            }

            $tasks->markCompleted($task, [
                'message' => $message,
                'data' => $portfolio->fresh(['creator:id,full_name,username']),
            ]);
        } catch (\Throwable $e) {
            $this->failBackgroundTask($tasks, $task, $e, 'GenerateMarketingPortfolioJob');
        }
    }
}
