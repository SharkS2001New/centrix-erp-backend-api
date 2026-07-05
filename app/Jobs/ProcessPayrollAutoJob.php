<?php

namespace App\Jobs;

use App\Http\Controllers\Api\V1\Operations\PayrollOperationsController;
use App\Models\BackgroundTask;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use App\Services\Payroll\PayrollAutoProcessService;
use App\Services\Payroll\PayrollRunScheduleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProcessPayrollAutoJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(
        BackgroundTaskService $tasks,
        PayrollAutoProcessService $autoProcess,
    ): void {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($task === null) {
            return;
        }

        $tasks->markRunning($task);

        try {
            $user = User::query()->find($task->user_id);
            if ($user === null) {
                throw new \RuntimeException('User not found for payroll auto-process task.');
            }

            $payload = is_array($task->payload) ? $task->payload : [];
            $runId = (int) ($payload['run_id'] ?? 0);
            $run = PayrollRun::with('payPeriod')->findOrFail($runId);

            if ($run->payPeriod) {
                app(PayrollRunScheduleService::class)->assertCanRunPayrollForPeriod($run->payPeriod);
            }

            $built = $autoProcess->buildLines($run, (int) $task->organization_id, $payload['options'] ?? []);
            if ($built['lines'] === []) {
                throw new \RuntimeException('No eligible employees to process.');
            }

            $request = Request::create("/payroll/runs/{$runId}/process-auto", 'POST', [
                'auto_calculate' => true,
                'close_cycle' => true,
                'include_overtime' => (bool) ($payload['options']['include_overtime'] ?? true),
                'include_other_deductions' => (bool) (
                    $payload['options']['include_other_deductions']
                    ?? $payload['options']['include_deductions']
                    ?? true
                ),
                'lines' => $built['lines'],
            ]);
            $request->setUserResolver(fn () => $user);

            $response = app(PayrollOperationsController::class)->processRun($request, (string) $runId);
            $data = $response->getData(true);
            if (is_array($data)) {
                $data['skipped_employees'] = $built['skipped'];
                $data['processed_count'] = count($built['lines']);
            }

            $tasks->markCompleted($task, is_array($data) ? $data : ['status' => 'completed']);
        } catch (\Throwable $e) {
            Log::warning('ProcessPayrollAutoJob failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
            $tasks->markFailed($task, $e->getMessage());
            throw $e;
        }
    }
}
