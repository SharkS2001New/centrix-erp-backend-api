<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Models\Product;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use App\Services\Catalog\ProductCatalogScopeService;
use App\Services\Erp\ErpContext;
use App\Services\Kra\KraDeviceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RegisterKraProductsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(
        BackgroundTaskService $tasks,
        ErpContext $erp,
        ProductCatalogScopeService $catalogScope,
    ): void {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($task === null || ! $tasks->markRunning($task)) {
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

            $products = $query->with(['vat', 'unit'])->orderBy('product_name')->get();
            if ($products->isEmpty()) {
                throw new \RuntimeException('No matching active products found.');
            }

            $path = trim((string) ($finance['kra_plu_register_path'] ?? '/api/register-plu'));
            $service = KraDeviceService::fromSettings($finance);
            $result = $service->registerProducts($products->all(), $path, $finance);

            if (empty($result['success'])) {
                if ($service->isAlreadyRegisteredPluResult($result)) {
                    $skipped = (int) ($result['product_count'] ?? $products->count());
                    $tasks->markCompleted($task, [
                        'success' => true,
                        'message' => $skipped === 1
                            ? '1 product was already on the KRA device (skipped).'
                            : "{$skipped} products were already on the KRA device (skipped).",
                        'registered_count' => (int) ($result['registered_count'] ?? 0),
                        'skipped_count' => max($skipped, (int) ($result['skipped_count'] ?? 0)),
                        'product_count' => $skipped,
                        'response' => $result['response'] ?? null,
                    ]);

                    return;
                }

                throw new \RuntimeException((string) ($result['message'] ?? 'KRA registration failed.'));
            }

            $tasks->markCompleted($task, $result);
        } catch (\Throwable $e) {
            $alreadyRegistered = app(KraDeviceService::class)->isAlreadyRegisteredPluResult([
                'message' => $e->getMessage(),
            ]);
            if ($alreadyRegistered) {
                $tasks->markCompleted($task, [
                    'success' => true,
                    'message' => 'Products were already on the KRA device (skipped).',
                    'registered_count' => 0,
                    'skipped_count' => (int) ($task->payload['product_codes'] ?? []) !== []
                        ? count((array) $task->payload['product_codes'])
                        : 1,
                ]);

                return;
            }

            Log::warning('RegisterKraProductsJob failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
            $tasks->markFailed($task, $e->getMessage());
            throw $e;
        }
    }
}
