<?php

namespace App\Jobs;

use App\Http\Controllers\Api\V1\Operations\PayrollOperationsController;
use App\Models\BackgroundTask;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use App\Services\Payroll\PayrollAutoProcessService;
use App\Services\Payroll\PayrollRunScheduleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

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
        if ($task === null || ! $tasks->markRunning($task)) {
            return;
        }

        try {
            $user = User::query()->find($task->user_id);
            if ($user === null) {
                throw new \RuntimeException('User not found for payroll auto-process task.');
            }

            $payload = is_array($task->payload) ? $task->payload : [];
            $runId = (int) ($payload['run_id'] ?? 0);
            $run = PayrollRun::with('payPeriod')->find($runId);
            if ($run === null) {
                throw new \RuntimeException(
                    'Payroll run not found. It may have been deleted before auto-process finished.',
                );
            }

            if ($run->payPeriod) {
                app(PayrollRunScheduleService::class)->assertCanRunPayrollForPeriod($run->payPeriod);
            }

            $tasks->updateProgress($task, 3, 'Preparing employee payroll…');

            $built = $autoProcess->buildLines(
                $run,
                (int) $task->organization_id,
                $payload['options'] ?? [],
                function (int $done, int $total, string $employeeName) use ($tasks, $task) {
                    $pct = 5 + (int) floor(($done / max(1, $total)) * 70);
                    $tasks->updateProgress(
                        $task,
                        min(75, $pct),
                        "Calculating {$employeeName} ({$done}/{$total})…",
                        $done,
                        $total,
                    );
                },
            );

            if ($built['lines'] === []) {
                throw new \RuntimeException('No eligible employees to process.');
            }

            $tasks->updateProgress($task, 82, 'Writing payroll lines…');

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
            $request->headers->set('Accept', 'application/json');
            $request->setUserResolver(fn () => $user);

            $response = app(PayrollOperationsController::class)->processRun($request, (string) $runId);
            $data = $response->getData(true);
            if (is_array($data)) {
                $data['skipped_employees'] = $built['skipped'];
                $data['processed_count'] = count($built['lines']);
            }

            $tasks->updateProgress($task, 98, 'Finalizing payroll run…');
            $tasks->markCompleted($task, is_array($data) ? $data : ['status' => 'completed']);
        } catch (Throwable $e) {
            $message = $this->friendlyFailureMessage($e);
            Log::warning('ProcessPayrollAutoJob failed', [
                'task_id' => $this->taskId,
                'error' => $message,
            ]);
            $tasks->markFailed($task, $message);

            // Operational outcomes (deleted run, empty roster, validation) already
            // live on the background task — do not fail the queue worker / HIGH alert.
            if ($this->isExpectedOperationalFailure($e)) {
                return;
            }

            throw $e;
        }
    }

    protected function friendlyFailureMessage(Throwable $e): string
    {
        if ($e instanceof ModelNotFoundException) {
            if ($e->getModel() === PayrollRun::class) {
                return 'Payroll run not found. It may have been deleted before auto-process finished.';
            }
            if ($e->getModel() === PayrollLine::class) {
                return 'Payroll line not found. It may have been excluded or the run was deleted.';
            }
        }

        if ($e instanceof HttpExceptionInterface) {
            $msg = trim((string) $e->getMessage());
            if ($msg !== '') {
                return $msg;
            }
        }

        if ($e instanceof ValidationException) {
            $first = collect($e->errors())->flatten()->first();
            if (is_string($first) && $first !== '') {
                return $first;
            }
        }

        return $e->getMessage() !== '' ? $e->getMessage() : class_basename($e);
    }

    protected function isExpectedOperationalFailure(Throwable $e): bool
    {
        if ($e instanceof ModelNotFoundException) {
            return in_array($e->getModel(), [PayrollRun::class, PayrollLine::class, User::class], true);
        }

        if ($e instanceof ValidationException) {
            return true;
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return $status === 404 || $status === 422;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'payroll run not found')
            || str_contains($message, 'may have been deleted')
            || str_contains($message, 'no eligible employees')
            || str_contains($message, 'user not found for payroll');
    }
}
