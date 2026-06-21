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
        if ($task === null) {
            return;
        }

        $tasks->markRunning($task);

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

            $products = $query->orderBy('product_name')->get();
            if ($products->isEmpty()) {
                throw new \RuntimeException('No matching active products found.');
            }

            $path = trim((string) ($finance['kra_plu_register_path'] ?? '/api/register-plu'));
            $service = KraDeviceService::fromSettings($finance);
            $result = $service->registerProducts($products->all(), $path);

            if (empty($result['success'])) {
                throw new \RuntimeException((string) ($result['message'] ?? 'KRA registration failed.'));
            }

            $tasks->markCompleted($task, $result);
        } catch (\Throwable $e) {
            Log::warning('RegisterKraProductsJob failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
            $tasks->markFailed($task, $e->getMessage());
            throw $e;
        }
    }
}
