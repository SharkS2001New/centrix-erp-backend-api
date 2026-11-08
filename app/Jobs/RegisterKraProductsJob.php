<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsBackgroundTaskOnce;
use App\Models\BackgroundTask;
use App\Models\Product;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use App\Services\Catalog\ProductCatalogScopeService;
use App\Services\Erp\ErpContext;
use App\Services\Kra\KraAutoRetryPolicy;
use App\Services\Kra\KraDeviceErrorTranslator;
use App\Services\Kra\KraDeviceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RegisterKraProductsJob implements ShouldQueue
{
    use Queueable;
    use RunsBackgroundTaskOnce;

    /**
     * Large catalogues + per-item KRA retries can exceed 15 minutes easily.
     * Keep ≤ queue worker --timeout (see centrix-erp-queue-worker values).
     */
    public int $timeout;

    public function __construct(
        public string $taskId,
    ) {
        $this->timeout = max(
            3600,
            (int) config('background.job_timeout_long_seconds', 7200),
        );
        // Cap automatic recoveries; after this the UI lists failed products for manual upload.
        $this->tries = KraAutoRetryPolicy::maxAttempts();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        $delays = config('kra.auto_retry_delay_seconds', KraAutoRetryPolicy::JOB_BACKOFF_SECONDS);

        return array_values(array_map('intval', is_array($delays) ? $delays : KraAutoRetryPolicy::JOB_BACKOFF_SECONDS));
    }

    public function handle(
        BackgroundTaskService $tasks,
        ErpContext $erp,
        ProductCatalogScopeService $catalogScope,
    ): void {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($this->shouldSkipBackgroundTask($task) || ! $tasks->markRunning($task)) {
            return;
        }

        try {
            $user = User::query()->find($task->user_id);
            if ($user === null) {
                throw new \RuntimeException('User not found for KRA registration task.');
            }

            $payload = $task->payload ?? [];
            $productCodes = $payload['product_codes'] ?? [];
            $registerAll = ! empty($payload['all']);

            $gate = $erp->gateForUser($user);
            $finance = $gate->moduleSettings('finance');
            if (empty($finance['enable_kra_device'])) {
                throw new \RuntimeException('KRA fiscal device is not enabled for this organization.');
            }

            $query = Product::query()->whereNull('deleted_at');
            $catalogScope->scopeForUser($query, $user);

            if (! $registerAll) {
                $query->whereIn('product_code', $productCodes);
            }

            $total = (clone $query)->count();
            if ($total === 0) {
                throw new \RuntimeException('No matching active products found.');
            }

            $path = trim((string) ($finance['kra_plu_register_path'] ?? '/api/register-plu'));
            $service = KraDeviceService::fromSettings(
                $finance,
                $gate->organization()?->id ? (int) $gate->organization()->id : null,
            );

            $batchSize = max(1, (int) (
                $finance['kra_plu_upload_batch_size']
                ?? config('erp.module_settings_defaults.finance.kra_plu_upload_batch_size', 50)
            ));
            // Page the catalogue so memory stays flat for tens of thousands of SKUs.
            $pageSize = max($batchSize, 100);

            $registered = 0;
            $skipped = 0;
            $skippedProducts = [];
            $lastResponse = null;
            $processed = 0;
            $halted = false;
            $haltResult = null;

            $tasks->updateProgress($task, 1, "Preparing {$total} products for KRA…", 0, $total);

            $query->with(['vat', 'unit'])
                ->orderBy('id')
                ->chunkById($pageSize, function ($products) use (
                    $tasks,
                    $task,
                    $service,
                    $path,
                    $finance,
                    $total,
                    &$registered,
                    &$skipped,
                    &$skippedProducts,
                    &$lastResponse,
                    &$processed,
                    &$halted,
                    &$haltResult,
                ) {
                    $tasks->assertNotCancelled($task);

                    $pageItems = $products->all();
                    $pageCount = count($pageItems);
                    if ($pageCount === 0) {
                        return true;
                    }

                    $result = $service->registerProducts(
                        $pageItems,
                        $path,
                        $finance,
                        function (int $chunkProcessed) use (
                            $tasks,
                            $task,
                            &$processed,
                            $total,
                        ): void {
                            $done = min($total, $processed + $chunkProcessed);
                            $pct = (int) max(1, min(99, round(($done / max(1, $total)) * 100)));
                            $tasks->updateProgress(
                                $task,
                                $pct,
                                "Registering products on KRA ({$done} of {$total})…",
                                $done,
                                $total,
                            );
                        },
                    );

                    if (empty($result['success'])) {
                        if (KraDeviceService::isAlreadyRegisteredPluResult($result)) {
                            $pageSkipped = (int) ($result['product_count'] ?? $pageCount);
                            $skipped += max($pageSkipped, (int) ($result['skipped_count'] ?? 0));
                            $registered += (int) ($result['registered_count'] ?? 0);
                            $skippedProducts = array_merge(
                                $skippedProducts,
                                $result['skipped_products'] ?? [],
                            );
                            $lastResponse = $result['response'] ?? $lastResponse;
                            $processed += $pageCount;
                            $tasks->updateProgress(
                                $task,
                                (int) max(1, min(99, round(($processed / max(1, $total)) * 100))),
                                "Registering products on KRA ({$processed} of {$total})…",
                                $processed,
                                $total,
                            );

                            return true;
                        }

                        $halted = true;
                        $haltResult = $result;
                        $registered += (int) ($result['registered_count'] ?? 0);
                        $skipped += (int) ($result['skipped_count'] ?? 0);
                        $skippedProducts = array_merge(
                            $skippedProducts,
                            $result['skipped_products'] ?? [],
                        );
                        $processed += (int) ($result['registered_count'] ?? 0)
                            + (int) ($result['skipped_count'] ?? 0);

                        return false;
                    }

                    $registered += (int) ($result['registered_count'] ?? 0);
                    $skipped += (int) ($result['skipped_count'] ?? 0);
                    $skippedProducts = array_merge(
                        $skippedProducts,
                        $result['skipped_products'] ?? [],
                    );
                    $lastResponse = $result['response'] ?? $lastResponse;
                    $processed += $pageCount;

                    $tasks->updateProgress(
                        $task,
                        (int) max(1, min(99, round(($processed / max(1, $total)) * 100))),
                        "Registering products on KRA ({$processed} of {$total})…",
                        $processed,
                        $total,
                    );

                    return true;
                });

            if ($halted) {
                $failMessage = (string) ($haltResult['message'] ?? 'KRA registration failed. Check that Centrix KRA Agent is running.');
                $failedProducts = array_slice(
                    array_merge($skippedProducts, $haltResult['skipped_products'] ?? []),
                    0,
                    200,
                );

                if ($this->shouldScheduleAutoRetry($failMessage)) {
                    $tasks->requeueForAutoRetry(
                        $task,
                        $this->attempts(),
                        "KRA agent unreachable — auto-retry {$this->attempts()}/{$this->tries} scheduled…",
                    );
                    throw new \RuntimeException($failMessage);
                }

                $tasks->markFailed($task, $failMessage, [
                    'success' => false,
                    'message' => $failMessage,
                    'registered_count' => $registered,
                    'skipped_count' => $skipped,
                    'skipped_products' => $failedProducts,
                    'failed_products' => $failedProducts,
                    'product_count' => $total,
                    'auto_retry_exhausted' => true,
                    'manual_retry_required' => true,
                ]);

                return;
            }

            $tasks->markCompleted($task, [
                'success' => true,
                'message' => $this->summaryMessage($registered, $skipped, $skippedProducts),
                'registered_count' => $registered,
                'skipped_count' => $skipped,
                'skipped_products' => array_slice($skippedProducts, 0, 200),
                'failed_products' => array_values(array_filter(
                    array_slice($skippedProducts, 0, 200),
                    static fn (array $row) => ($row['reason'] ?? '') === 'rejected',
                )),
                'product_count' => $total,
                'response' => $lastResponse,
                'manual_retry_required' => $skipped > 0 && $registered === 0,
            ]);
        } catch (\Throwable $e) {
            if (KraDeviceService::isAlreadyRegisteredPluResult(['message' => $e->getMessage()])) {
                $tasks->markCompleted($task, [
                    'success' => true,
                    'message' => 'Products were already on the KRA device (skipped).',
                    'registered_count' => 0,
                    'skipped_count' => ! empty($task->payload['product_codes'])
                        ? count((array) $task->payload['product_codes'])
                        : 1,
                ]);

                return;
            }

            if ($this->shouldScheduleAutoRetry($e->getMessage())) {
                $tasks->requeueForAutoRetry(
                    $task,
                    $this->attempts(),
                    "KRA agent unreachable — auto-retry {$this->attempts()}/{$this->tries} scheduled…",
                );
                throw $e;
            }

            $this->failBackgroundTask($tasks, $task, $e, 'RegisterKraProductsJob');
        }
    }

    protected function shouldScheduleAutoRetry(string $message): bool
    {
        if ($this->attempts() >= $this->tries) {
            return false;
        }

        return KraDeviceErrorTranslator::isTransientConnectivityFailure($message);
    }

    /**
     * @param  list<array<string, string>>  $skippedProducts
     */
    protected function summaryMessage(int $registered, int $skipped, array $skippedProducts): string
    {
        if ($registered === 0 && $skipped > 0) {
            return $skipped === 1
                ? '1 product was already on the KRA device (skipped).'
                : "{$skipped} products were already on the KRA device (skipped).";
        }

        if ($skipped === 0) {
            return $registered === 1
                ? '1 product registered on the KRA device.'
                : "{$registered} products registered on the KRA device.";
        }

        $sample = $skippedProducts[0]['product_code'] ?? null;

        return "{$registered} registered, {$skipped} skipped"
            .($sample ? " (e.g. {$sample})" : '').'.';
    }
}
