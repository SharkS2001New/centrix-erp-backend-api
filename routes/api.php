<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ErpSettingsController;
use App\Http\Controllers\Api\V1\AiSettingsController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationProvisionController;
use App\Http\Controllers\Api\V1\PlatformActiveSessionsController;
use App\Http\Controllers\Api\V1\BackgroundTaskController;
use App\Http\Controllers\Api\V1\PlatformDatabaseBackupController;
use App\Http\Controllers\Api\V1\PlatformOrganizationCacheController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\TillController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\TillFloatSessionController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SupplierPaymentController;
use App\Http\Controllers\Api\V1\VatController;
use App\Http\Controllers\Api\V1\UomController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\SubCategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\RetailPackageSettingController;
use App\Http\Controllers\Api\V1\PriceHistoryController;
use App\Http\Controllers\Api\V1\RouteModelController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CurrentStockController;
use App\Http\Controllers\Api\V1\InventoryTransactionController;
use App\Http\Controllers\Api\V1\DamageController;
use App\Http\Controllers\Api\V1\StockReceiptController;
use App\Http\Controllers\Api\V1\SupplierReturnDocumentController;
use App\Http\Controllers\Api\V1\SupplierReturnController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\VoucherController;
use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\MobileLoadingSheetController;
use App\Http\Controllers\Api\V1\MobileFieldAttendanceController;
use App\Http\Controllers\Api\V1\SaleItemController;
use App\Http\Controllers\Api\V1\SalePaymentController;
use App\Http\Controllers\Api\V1\TemporaryCartController;
use App\Http\Controllers\Api\V1\CartLineController;
use App\Http\Controllers\Api\V1\CustomerInvoiceController;
use App\Http\Controllers\Api\V1\CustomerInvoicePaymentController;
use App\Http\Controllers\Api\V1\LpoStatusController;
use App\Http\Controllers\Api\V1\LpoMstController;
use App\Http\Controllers\Api\V1\LpoTxnController;
use App\Http\Controllers\Api\V1\LpoAttachmentController;
use App\Http\Controllers\Api\V1\LpoSupplierInvoiceController;
use App\Http\Controllers\Api\V1\ReturnRecordController;
use App\Http\Controllers\Api\V1\CustomerReturnController;
use App\Http\Controllers\Api\V1\ExpenseGroupController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\KraResponseController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\SystemSettingController;
use App\Http\Controllers\Api\V1\ErpCapabilitiesController;
use App\Http\Controllers\Api\V1\StockReservationController;

use App\Http\Controllers\Api\V1\Operations\MpesaPaymentController;

Route::prefix('v1')->group(function () {
    Route::get('health', [AuthController::class, 'health']);
    Route::get('auth/organization-preview', [AuthController::class, 'organizationPreview'])
        ->middleware('throttle:auth-org-preview');
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth-login');
    Route::post('auth/logout', [AuthController::class, 'logout'])
        ->middleware('throttle:auth-login');
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:auth-password');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:auth-password');
    // Safaricom rejects callback URLs containing the word "mpesa" in the path.
    Route::middleware('erp.mpesa_callback_ip')->group(function () {
        Route::post('payments/stk/callback', [MpesaPaymentController::class, 'stkCallback']);
        Route::post('payments/c2b/validation', [MpesaPaymentController::class, 'validationRequest']);
        Route::post('payments/c2b/confirmation', [MpesaPaymentController::class, 'c2bConfirmation']);
    });
    Route::get('accounting/quickbooks/callback', [\App\Http\Controllers\Api\V1\Operations\ExternalAccountingController::class, 'quickBooksCallback'])
        ->middleware('throttle:auth-org-preview');

    Route::middleware(['auth:sanctum', 'erp.tenant', 'erp.session_idle', 'throttle:api'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);
        Route::post('auth/set-required-password', [AuthController::class, 'setRequiredPassword']);
        Route::post('auth/verify-password', [AuthController::class, 'verifyPassword']);
        Route::get('auth/memberships', [AuthController::class, 'memberships']);
        Route::post('auth/switch-organization', [AuthController::class, 'switchOrganization']);
        Route::post('auth/switch-workspace', [AuthController::class, 'switchWorkspace']);

        Route::get('erp/capabilities', [ErpCapabilitiesController::class, 'show']);
        Route::get('erp/organization/profile', [OrganizationController::class, 'currentProfile'])
            ->middleware(['erp.module:admin', 'erp.permission:admin.company.view|admin.view']);
        Route::patch('erp/organization/profile', [OrganizationController::class, 'updateCurrentProfile'])
            ->middleware(['erp.module:admin', 'erp.permission:admin.company.edit|admin.manage']);
        Route::get('erp/profiles', [ErpCapabilitiesController::class, 'profiles'])
            ->middleware(['erp.module:admin', 'erp.permission:admin.manage']);
        Route::get('erp/settings/sales', [ErpSettingsController::class, 'sales'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::patch('erp/settings/sales', [ErpSettingsController::class, 'updateSales'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::get('erp/settings/distribution', [ErpSettingsController::class, 'distribution'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::patch('erp/settings/distribution', [ErpSettingsController::class, 'updateDistribution'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::get('erp/settings/inventory', [ErpSettingsController::class, 'inventory'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::patch('erp/settings/inventory', [ErpSettingsController::class, 'updateInventory'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::get('erp/settings/finance', [ErpSettingsController::class, 'finance'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::patch('erp/settings/finance', [ErpSettingsController::class, 'updateFinance'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::get('erp/settings/ai', [\App\Http\Controllers\Api\V1\AiSettingsController::class, 'show'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::patch('erp/settings/ai', [\App\Http\Controllers\Api\V1\AiSettingsController::class, 'update'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::get('erp/settings/general', [ErpSettingsController::class, 'general'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::patch('erp/settings/general', [ErpSettingsController::class, 'updateGeneral'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::get('erp/settings/notifications', [ErpSettingsController::class, 'notifications'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::patch('erp/settings/notifications', [ErpSettingsController::class, 'updateNotifications'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::get('erp/settings/procurement', [ErpSettingsController::class, 'procurement'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::patch('erp/settings/procurement', [ErpSettingsController::class, 'updateProcurement'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::get('erp/settings/security', [ErpSettingsController::class, 'security'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::patch('erp/settings/security', [ErpSettingsController::class, 'updateSecurity'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::get('erp/settings/hr', [ErpSettingsController::class, 'hr'])
            ->middleware(['erp.forbid_tenant_settings']);
        Route::patch('erp/settings/hr', [ErpSettingsController::class, 'updateHr'])
            ->middleware(['erp.forbid_tenant_settings']);

        Route::get('admin/organizations/provision-options', [OrganizationProvisionController::class, 'options'])
            ->middleware(['erp.super_admin']);
        Route::get('background-tasks/{id}', [BackgroundTaskController::class, 'show']);

        Route::get('admin/organizations', [OrganizationProvisionController::class, 'index'])
            ->middleware(['erp.super_admin']);
        Route::post('admin/organizations/provision', [OrganizationProvisionController::class, 'store'])
            ->middleware(['erp.super_admin', 'erp.org_provisioning']);
        Route::get('admin/organizations/{organization}', [OrganizationProvisionController::class, 'show'])
            ->middleware(['erp.super_admin']);
        Route::patch('admin/organizations/{organization}', [OrganizationProvisionController::class, 'update'])
            ->middleware(['erp.super_admin']);
        Route::get('admin/organizations/{organization}/users', [OrganizationProvisionController::class, 'listUsers'])
            ->middleware(['erp.super_admin']);
        Route::post('admin/organizations/{organization}/users', [OrganizationProvisionController::class, 'createUser'])
            ->middleware(['erp.super_admin']);
        Route::patch('admin/organizations/{organization}/users/{user}', [OrganizationProvisionController::class, 'updateUser'])
            ->middleware(['erp.super_admin']);
        Route::get('admin/active-sessions', [PlatformActiveSessionsController::class, 'index'])
            ->middleware(['erp.super_admin']);
        Route::delete('admin/active-sessions/{token}', [PlatformActiveSessionsController::class, 'destroy'])
            ->middleware(['erp.super_admin']);
        Route::post('admin/active-sessions/{token}/disable-user', [PlatformActiveSessionsController::class, 'disableUser'])
            ->middleware(['erp.super_admin']);

        Route::get('admin/database-backups', [PlatformDatabaseBackupController::class, 'index'])
            ->middleware(['erp.super_admin']);
        Route::post('admin/database-backups', [PlatformDatabaseBackupController::class, 'store'])
            ->middleware(['erp.super_admin']);
        Route::get('admin/database-backups/{filename}/download', [PlatformDatabaseBackupController::class, 'download'])
            ->middleware(['erp.super_admin']);

        Route::get('admin/organizations/{organization}/cache', [PlatformOrganizationCacheController::class, 'show'])
            ->middleware(['erp.super_admin']);
        Route::post('admin/organizations/{organization}/cache/clear', [PlatformOrganizationCacheController::class, 'clear'])
            ->middleware(['erp.super_admin']);

        Route::prefix('admin/organizations/{organization}/settings')
            ->middleware(['erp.super_admin', 'erp.act_as_organization'])
            ->group(function () {
                Route::get('sales', [ErpSettingsController::class, 'sales']);
                Route::patch('sales', [ErpSettingsController::class, 'updateSales']);
                Route::get('distribution', [ErpSettingsController::class, 'distribution']);
                Route::patch('distribution', [ErpSettingsController::class, 'updateDistribution']);
                Route::get('inventory', [ErpSettingsController::class, 'inventory']);
                Route::patch('inventory', [ErpSettingsController::class, 'updateInventory']);
                Route::get('finance', [ErpSettingsController::class, 'finance']);
                Route::patch('finance', [ErpSettingsController::class, 'updateFinance']);
                Route::get('ai', [AiSettingsController::class, 'show']);
                Route::patch('ai', [AiSettingsController::class, 'update']);
                Route::get('general', [ErpSettingsController::class, 'general']);
                Route::patch('general', [ErpSettingsController::class, 'updateGeneral']);
                Route::get('notifications', [ErpSettingsController::class, 'notifications']);
                Route::patch('notifications', [ErpSettingsController::class, 'updateNotifications']);
                Route::get('procurement', [ErpSettingsController::class, 'procurement']);
                Route::patch('procurement', [ErpSettingsController::class, 'updateProcurement']);
                Route::get('security', [ErpSettingsController::class, 'security']);
                Route::patch('security', [ErpSettingsController::class, 'updateSecurity']);
                Route::get('legacy-archive', [ErpSettingsController::class, 'legacyArchive']);
                Route::patch('legacy-archive', [ErpSettingsController::class, 'updateLegacyArchive']);
                Route::get('hr', [ErpSettingsController::class, 'hr']);
                Route::patch('hr', [ErpSettingsController::class, 'updateHr']);
            });

        Route::prefix('admin/organizations/{organization}')
            ->middleware(['erp.super_admin', 'erp.act_as_organization'])
            ->group(function () {
                Route::post('logo', function (\Illuminate\Http\Request $request, $organization) {
                    return app(OrganizationController::class)->uploadLogo($request, (string) $organization);
                });
                Route::get('logo/file', function (\Illuminate\Http\Request $request, $organization) {
                    return app(OrganizationController::class)->logoFile($request, (string) $organization);
                });
                Route::delete('logo', function (\Illuminate\Http\Request $request, $organization) {
                    return app(OrganizationController::class)->deleteLogo($request, (string) $organization);
                });
                Route::apiResource('branches', BranchController::class);
                Route::get('roles/permissions/matrix', [RoleController::class, 'permissionMatrix']);
                Route::get('roles/{role}/permissions', [RoleController::class, 'permissions']);
                Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions']);
                Route::apiResource('roles', RoleController::class);
                Route::apiResource('payment-methods', PaymentMethodController::class);
                Route::apiResource('audit-logs', AuditLogController::class)->only(['index', 'show']);
                Route::apiResource('users', UserController::class);
                Route::get('users/{user}/permissions', [UserController::class, 'permissions']);
                Route::put('users/{user}/permissions', [UserController::class, 'syncPermissions']);
                Route::apiResource('routes', RouteModelController::class)->only(['index', 'show']);
                Route::apiResource('employees', EmployeeController::class)->only(['index', 'show']);
            });

        Route::middleware(['erp.module:admin'])->group(function () {
            Route::apiResource('organizations', OrganizationController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:admin.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:admin.manage']);
            Route::post('organizations/{organization}/logo', [OrganizationController::class, 'uploadLogo'])
                ->middleware(['erp.permission:admin.manage']);
            Route::get('organizations/{organization}/logo/file', [OrganizationController::class, 'logoFile'])
                ->middleware(['erp.permission:admin.view']);
            Route::delete('organizations/{organization}/logo', [OrganizationController::class, 'deleteLogo'])
                ->middleware(['erp.permission:admin.manage']);
            Route::apiResource('branches', BranchController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:admin.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:admin.manage']);
            Route::get('roles/permissions/matrix', [RoleController::class, 'permissionMatrix'])
                ->middleware('erp.permission:admin.view');
            Route::get('roles/{role}/permissions', [RoleController::class, 'permissions'])
                ->middleware('erp.permission:admin.view');
            Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])
                ->middleware('erp.permission:admin.manage');
            Route::apiResource('roles', RoleController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:admin.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:admin.manage']);
            Route::apiResource('permissions', PermissionController::class)
                ->middleware('erp.permission:admin.view');
            Route::apiResource('audit-logs', AuditLogController::class)
                ->middleware('erp.permission:admin.view');
            Route::apiResource('system-settings', SystemSettingController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:admin.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:admin.manage']);
            Route::apiResource('payment-methods', PaymentMethodController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:admin.view|purchasing.view|payments.view|payments.manage'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:admin.manage']);
            Route::apiResource('kra-responses', KraResponseController::class)
                ->middleware('erp.permission:admin.view');
        });
        Route::apiResource('users', UserController::class)
            ->middleware(['erp.module:admin'])
            ->middlewareFor(['index', 'show'], ['erp.permission:admin.view'])
            ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:admin.manage']);
        Route::get('users/{user}/permissions', [UserController::class, 'permissions'])
            ->middleware(['erp.module:admin', 'erp.permission:admin.view']);
        Route::put('users/{user}/permissions', [UserController::class, 'syncPermissions'])
            ->middleware(['erp.module:admin', 'erp.permission:admin.manage']);
        Route::post('users/{user}/memberships', [UserController::class, 'addMembership'])
            ->middleware(['erp.module:admin', 'erp.permission:admin.manage']);

        Route::middleware('erp.module:sales.pos')->group(function () {
            Route::apiResource('tills', TillController::class)
                ->middleware('erp.permission:pos.till');
            Route::apiResource('till-float-sessions', TillFloatSessionController::class)
                ->middleware('erp.permission:pos.till');
        });

        Route::middleware(['erp.module:inventory'])->group(function () {
            Route::apiResource('vats', VatController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:catalogue.view|pos.checkout.create|pos.terminal.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:products.manage']);
            Route::apiResource('uoms', UomController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:catalogue.view|pos.checkout.create|pos.terminal.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:products.manage']);
            Route::apiResource('categories', CategoryController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:catalogue.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:products.manage']);
            Route::apiResource('sub-categories', SubCategoryController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:catalogue.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:products.manage']);
            Route::get('products/catalog-summary', [ProductController::class, 'catalogSummary'])
                ->middleware(['erp.permission:catalogue.view|pos.checkout.create|pos.terminal.view']);
            Route::post('products/import-batch', [\App\Http\Controllers\Api\V1\ProductImportController::class, 'store'])
                ->middleware(['erp.permission:products.manage']);
            Route::apiResource('products', ProductController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:catalogue.view|pos.checkout.create|pos.terminal.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:products.manage']);
            Route::apiResource('retail-package-settings', RetailPackageSettingController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:catalogue.view|pos.checkout.create|pos.terminal.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:products.manage']);
            Route::apiResource('price-history', PriceHistoryController::class)
                ->middleware('erp.permission:catalogue.view');
            Route::apiResource('current-stock', CurrentStockController::class)
                ->only(['index', 'show'])
                ->middleware('erp.permission:inventory.view');
            Route::apiResource('inventory-transactions', InventoryTransactionController::class)
                ->middleware('erp.permission:inventory.view');
            Route::apiResource('damages', DamageController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:inventory.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:inventory.manage']);
            Route::apiResource('stock-receipts', StockReceiptController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:inventory.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:inventory.manage']);
            Route::apiResource('supplier-returns', SupplierReturnController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:purchasing.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:purchasing.manage']);
            Route::get('supplier-return-documents', [SupplierReturnDocumentController::class, 'index'])
                ->middleware('erp.permission:purchasing.view');
            Route::post('supplier-return-documents', [SupplierReturnDocumentController::class, 'store'])
                ->middleware('erp.permission:purchasing.manage|purchasing.supplier_returns.create');
            Route::get('supplier-return-documents/{id}', [SupplierReturnDocumentController::class, 'show'])
                ->middleware('erp.permission:purchasing.view');
            Route::put('supplier-return-documents/{id}', [SupplierReturnDocumentController::class, 'update'])
                ->middleware('erp.permission:purchasing.manage|purchasing.supplier_returns.create');
            Route::delete('supplier-return-documents/{id}', [SupplierReturnDocumentController::class, 'destroy'])
                ->middleware('erp.permission:purchasing.manage|purchasing.supplier_returns.create');
            Route::post('supplier-return-documents/{id}/approve', [SupplierReturnDocumentController::class, 'approve'])
                ->middleware('erp.permission:purchasing.manage');
            Route::post('supplier-return-documents/{id}/reject', [SupplierReturnDocumentController::class, 'reject'])
                ->middleware('erp.permission:purchasing.manage');
            Route::apiResource('stock-reservations', StockReservationController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:inventory.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:inventory.manage']);
            Route::apiResource('stock-take-sessions', \App\Http\Controllers\Api\V1\StockTakeSessionController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:inventory.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:inventory.manage']);
            Route::apiResource('stock-take-lines', \App\Http\Controllers\Api\V1\StockTakeLineController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:inventory.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:inventory.manage']);
            Route::get('lpo-mst/dashboard', [LpoMstController::class, 'dashboard'])
                ->middleware('erp.permission:purchasing.view');
            Route::post('lpo-mst/full', [LpoMstController::class, 'storeFull'])
                ->middleware('erp.permission:purchasing.manage');
            Route::put('lpo-mst/{lpoNo}/full', [LpoMstController::class, 'updateFull'])
                ->middleware('erp.permission:purchasing.manage');
            Route::post('lpo-mst/{lpoNo}/workflow', [LpoMstController::class, 'workflow'])
                ->middleware('erp.permission:purchasing.manage');
            Route::get('lpo-mst/{lpoNo}/summary', [LpoMstController::class, 'summary'])
                ->middleware('erp.permission:purchasing.view');
            Route::apiResource('lpo-mst', LpoMstController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:purchasing.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:purchasing.manage']);
            Route::apiResource('lpo-txn', LpoTxnController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:purchasing.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:purchasing.manage']);
            Route::get('lpo-attachments/{attachment}/file', [LpoAttachmentController::class, 'file'])
                ->middleware('erp.permission:purchasing.view');
            Route::apiResource('lpo-attachments', LpoAttachmentController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:purchasing.view'])
                ->middlewareFor(['store', 'destroy'], ['erp.permission:purchasing.manage']);
            Route::apiResource('lpo-supplier-invoices', LpoSupplierInvoiceController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:purchasing.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:purchasing.manage']);
            Route::apiResource('lpo-statuses', LpoStatusController::class)
                ->middleware('erp.permission:purchasing.view');
        });

        Route::middleware(['erp.module:customers_suppliers'])->group(function () {
            Route::get('supplier-payments', [SupplierPaymentController::class, 'index'])
                ->middleware('erp.permission:purchasing.view');
            Route::get('suppliers/dashboard', [SupplierController::class, 'dashboard'])
                ->middleware('erp.permission:purchasing.view');
            Route::post('suppliers/recalculate-balances', [SupplierController::class, 'recalculateBalances'])
                ->middleware('erp.permission:purchasing.view');
            Route::get('suppliers/{supplier}/summary', [SupplierController::class, 'summary'])
                ->middleware('erp.permission:purchasing.view');
            Route::post('suppliers/{supplier}/payments', [SupplierController::class, 'storePayment'])
                ->middleware('erp.permission:purchasing.manage');
            Route::apiResource('suppliers', SupplierController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:purchasing.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:purchasing.manage']);
            Route::get('customers/{customer}/sales', [CustomerController::class, 'sales'])
                ->middleware('erp.permission:customers.view');
            Route::get('customers/{customer}/shop-image/file', [CustomerController::class, 'shopImageFile'])
                ->middleware('erp.permission:customers.view');
            Route::post('customers/{customer}/shop-image', [CustomerController::class, 'uploadShopImage'])
                ->middleware('erp.permission:customers.manage');
            Route::delete('customers/{customer}/shop-image', [CustomerController::class, 'deleteShopImage'])
                ->middleware('erp.permission:customers.manage');
            Route::get('customers/summary', [CustomerController::class, 'summary'])
                ->middleware(['erp.permission:customers.view']);
            Route::apiResource('customers', CustomerController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:customers.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:customers.manage']);
            Route::apiResource('routes', RouteModelController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:fulfillment.view|admin.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:fulfillment.manage']);
        });

        Route::middleware(['erp.module:sales.backend'])->group(function () {
            Route::apiResource('vouchers', VoucherController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:sales.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:sales.manage']);
            Route::apiResource('loyalty-cards', \App\Http\Controllers\Api\V1\LoyaltyCardController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:sales.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:sales.manage']);
            Route::get('sales/mobile-loading-sheets', [MobileLoadingSheetController::class, 'index'])
                ->middleware('erp.permission:sales.view');
            Route::get('sales/mobile-loading-sheets/detail', [MobileLoadingSheetController::class, 'show'])
                ->middleware('erp.permission:sales.view');
            Route::get('sales/mobile-field-attendance', [MobileFieldAttendanceController::class, 'index'])
                ->middleware('erp.permission:sales.view');
            Route::get('sales/mobile-field-attendance/{sessionId}', [MobileFieldAttendanceController::class, 'show'])
                ->middleware('erp.permission:sales.view');
            Route::patch('sales/mobile-field-attendance/{sessionId}', [MobileFieldAttendanceController::class, 'update'])
                ->middleware('erp.permission:sales.manage');
            Route::apiResource('sales', SaleController::class)
                ->middleware('erp.permission:sales.view');
            Route::apiResource('sale-items', SaleItemController::class)
                ->middleware('erp.permission:sales.view');
            Route::apiResource('temporary-carts', TemporaryCartController::class)
                ->middleware('erp.permission:sales.view');
            Route::apiResource('cart-lines', CartLineController::class)
                ->middleware('erp.permission:sales.view');
            Route::apiResource('returns', ReturnRecordController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:sales.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:sales.manage']);
            Route::get('sales/{saleId}/return-lines', [CustomerReturnController::class, 'saleLines'])
                ->middleware('erp.permission:sales.view');
            Route::post('customer-returns/{id}/approve', [CustomerReturnController::class, 'approve'])
                ->middleware('erp.permission:sales.manage');
            Route::post('customer-returns/{id}/reject', [CustomerReturnController::class, 'reject'])
                ->middleware('erp.permission:sales.manage');
            Route::apiResource('customer-returns', CustomerReturnController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:sales.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:sales.manage']);
        });

        Route::middleware(['erp.module:payments'])->group(function () {
            Route::apiResource('sale-payments', SalePaymentController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:payments.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:payments.manage']);
            Route::apiResource('customer-invoices', CustomerInvoiceController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:payments.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:payments.manage']);
            Route::apiResource('customer-invoice-payments', CustomerInvoicePaymentController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:payments.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:payments.manage']);
        });

        Route::middleware(['erp.module:accounting'])->group(function () {
            Route::apiResource('expense-groups', ExpenseGroupController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:accounting.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:accounting.manage']);
            Route::get('expenses/summary', [ExpenseController::class, 'summary'])
                ->middleware(['erp.permission:accounting.view']);
            Route::apiResource('expenses', ExpenseController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:accounting.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:accounting.manage']);
        });

        // Accounting — read vs manage
        Route::middleware('erp.module:accounting')->group(function () {
            Route::apiResource('chart-of-accounts', \App\Http\Controllers\Api\V1\ChartOfAccountController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:accounting.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:accounting.manage']);
            Route::apiResource('journal-entries', \App\Http\Controllers\Api\V1\JournalEntryController::class)
                ->except(['store'])
                ->middlewareFor(['index', 'show'], ['erp.permission:accounting.view'])
                ->middlewareFor(['update', 'destroy'], ['erp.permission:accounting.manage']);
            Route::apiResource('journal-entry-lines', \App\Http\Controllers\Api\V1\JournalEntryLineController::class)
                ->only(['index', 'show'])
                ->middleware('erp.permission:accounting.view');
        });
        Route::middleware(['erp.module:hr_payroll'])->group(function () {
            Route::apiResource('departments', \App\Http\Controllers\Api\V1\DepartmentController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:hr.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:hr.manage']);
            Route::get('employees/{employee}/photo/file', [\App\Http\Controllers\Api\V1\EmployeeController::class, 'photoFile'])
                ->middleware('erp.permission:hr.view');
            Route::post('employees/{employee}/photo', [\App\Http\Controllers\Api\V1\EmployeeController::class, 'uploadPhoto'])
                ->middleware('erp.permission:hr.manage');
            Route::delete('employees/{employee}/photo', [\App\Http\Controllers\Api\V1\EmployeeController::class, 'deletePhoto'])
                ->middleware('erp.permission:hr.manage');
            Route::get('employees/{employee}/payroll-lines', [\App\Http\Controllers\Api\V1\EmployeeController::class, 'payrollLines'])
                ->middleware('erp.permission:hr.view');
            Route::get('organization-kpis', [\App\Http\Controllers\Api\V1\OrganizationKpiController::class, 'index'])
                ->middleware('erp.permission:hr.kpis.view');
            Route::post('organization-kpis', [\App\Http\Controllers\Api\V1\OrganizationKpiController::class, 'store'])
                ->middleware('erp.permission:hr.kpis.create');
            Route::get('organization-kpis/{organizationKpi}', [\App\Http\Controllers\Api\V1\OrganizationKpiController::class, 'show'])
                ->middleware('erp.permission:hr.kpis.view');
            Route::put('organization-kpis/{organizationKpi}', [\App\Http\Controllers\Api\V1\OrganizationKpiController::class, 'update'])
                ->middleware('erp.permission:hr.kpis.edit');
            Route::delete('organization-kpis/{organizationKpi}', [\App\Http\Controllers\Api\V1\OrganizationKpiController::class, 'destroy'])
                ->middleware('erp.permission:hr.kpis.delete');
            Route::get('organization-kpis/{organizationKpi}/achievement', [\App\Http\Controllers\Api\V1\OrganizationKpiController::class, 'achievement'])
                ->middleware('erp.permission:hr.kpis.view');
            Route::post('organization-kpis/{organizationKpi}/assign', [\App\Http\Controllers\Api\V1\OrganizationKpiController::class, 'assign'])
                ->middleware('erp.permission:hr.kpis.edit');
            Route::get('employees/{employee}/kpis', [\App\Http\Controllers\Api\V1\EmployeeKpiController::class, 'summary'])
                ->middleware('erp.permission:hr.view');
            Route::post('employees/{employee}/kpis', [\App\Http\Controllers\Api\V1\EmployeeKpiController::class, 'store'])
                ->middleware('erp.permission:hr.manage');
            Route::put('employees/{employee}/kpis/{kpi}', [\App\Http\Controllers\Api\V1\EmployeeKpiController::class, 'update'])
                ->middleware('erp.permission:hr.manage');
            Route::delete('employees/{employee}/kpis/{kpi}', [\App\Http\Controllers\Api\V1\EmployeeKpiController::class, 'destroy'])
                ->middleware('erp.permission:hr.manage');
            Route::get('employees/{employee}/bank-accounts', [\App\Http\Controllers\Api\V1\EmployeeBankAccountController::class, 'index'])
                ->middleware('erp.permission:hr.view');
            Route::post('employees/{employee}/bank-accounts', [\App\Http\Controllers\Api\V1\EmployeeBankAccountController::class, 'store'])
                ->middleware('erp.permission:hr.manage');
            Route::put('employees/{employee}/bank-accounts/{bankAccount}', [\App\Http\Controllers\Api\V1\EmployeeBankAccountController::class, 'update'])
                ->middleware('erp.permission:hr.manage');
            Route::delete('employees/{employee}/bank-accounts/{bankAccount}', [\App\Http\Controllers\Api\V1\EmployeeBankAccountController::class, 'destroy'])
                ->middleware('erp.permission:hr.manage');
            Route::get('employees/{employee}/emergency-contacts', [\App\Http\Controllers\Api\V1\EmployeeEmergencyContactController::class, 'index'])
                ->middleware('erp.permission:hr.view');
            Route::post('employees/{employee}/emergency-contacts', [\App\Http\Controllers\Api\V1\EmployeeEmergencyContactController::class, 'store'])
                ->middleware('erp.permission:hr.manage');
            Route::put('employees/{employee}/emergency-contacts/{contact}', [\App\Http\Controllers\Api\V1\EmployeeEmergencyContactController::class, 'update'])
                ->middleware('erp.permission:hr.manage');
            Route::delete('employees/{employee}/emergency-contacts/{contact}', [\App\Http\Controllers\Api\V1\EmployeeEmergencyContactController::class, 'destroy'])
                ->middleware('erp.permission:hr.manage');
            Route::get('employees/{employee}/next-of-kin', [\App\Http\Controllers\Api\V1\EmployeeNextOfKinController::class, 'show'])
                ->middleware('erp.permission:hr.view');
            Route::put('employees/{employee}/next-of-kin', [\App\Http\Controllers\Api\V1\EmployeeNextOfKinController::class, 'upsert'])
                ->middleware('erp.permission:hr.manage');
            Route::delete('employees/{employee}/next-of-kin', [\App\Http\Controllers\Api\V1\EmployeeNextOfKinController::class, 'destroy'])
                ->middleware('erp.permission:hr.manage');
            Route::get('employees/{employee}/documents', [\App\Http\Controllers\Api\V1\EmployeeDocumentController::class, 'index'])
                ->middleware('erp.permission:hr.view');
            Route::post('employees/{employee}/documents', [\App\Http\Controllers\Api\V1\EmployeeDocumentController::class, 'store'])
                ->middleware('erp.permission:hr.manage');
            Route::get('employees/{employee}/documents/{document}/file', [\App\Http\Controllers\Api\V1\EmployeeDocumentController::class, 'file'])
                ->middleware('erp.permission:hr.view');
            Route::get('employees/{employee}/documents/{document}', [\App\Http\Controllers\Api\V1\EmployeeDocumentController::class, 'show'])
                ->middleware('erp.permission:hr.view');
            Route::put('employees/{employee}/documents/{document}', [\App\Http\Controllers\Api\V1\EmployeeDocumentController::class, 'update'])
                ->middleware('erp.permission:hr.manage');
            Route::delete('employees/{employee}/documents/{document}', [\App\Http\Controllers\Api\V1\EmployeeDocumentController::class, 'destroy'])
                ->middleware('erp.permission:hr.manage');
            Route::get('employees/summary', [\App\Http\Controllers\Api\V1\EmployeeController::class, 'summary'])
                ->middleware(['erp.permission:hr.view']);
            Route::apiResource('employees', \App\Http\Controllers\Api\V1\EmployeeController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:hr.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:hr.manage']);
            Route::apiResource('positions', \App\Http\Controllers\Api\V1\PositionController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:hr.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:hr.manage']);
            Route::apiResource('work-shifts', \App\Http\Controllers\Api\V1\WorkShiftController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:hr.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:hr.manage']);
            Route::get('organization-leave-settings', [\App\Http\Controllers\Api\V1\OrganizationLeaveSettingsController::class, 'show'])
                ->middleware('erp.permission:hr.view');
            Route::put('organization-leave-settings', [\App\Http\Controllers\Api\V1\OrganizationLeaveSettingsController::class, 'update'])
                ->middleware('erp.permission:hr.manage');
            Route::get('employee-leave-balances', [\App\Http\Controllers\Api\V1\EmployeeLeaveBalanceController::class, 'index'])
                ->middleware('erp.permission:hr.view');
            Route::post('employee-leave-balances/allocate-off-days', [\App\Http\Controllers\Api\V1\EmployeeLeaveBalanceController::class, 'allocateOffDays'])
                ->middleware('erp.permission:hr.manage');
            Route::put('employees/{employee}/leave-balances', [\App\Http\Controllers\Api\V1\EmployeeLeaveBalanceController::class, 'update'])
                ->middleware('erp.permission:hr.manage');
            Route::get('employees/{employee}/leave-balances', [\App\Http\Controllers\Api\V1\EmployeeLeaveDayController::class, 'balances'])
                ->middleware('erp.permission:hr.view');
            Route::get('employee-leave-days/calculate', [\App\Http\Controllers\Api\V1\EmployeeLeaveDayController::class, 'calculate'])
                ->middleware('erp.permission:hr.view');
            Route::post('employee-leave-days/{id}/approve', [\App\Http\Controllers\Api\V1\EmployeeLeaveDayController::class, 'approve'])
                ->middleware('erp.permission:hr.leave.approve');
            Route::post('employee-leave-days/{id}/reject', [\App\Http\Controllers\Api\V1\EmployeeLeaveDayController::class, 'reject'])
                ->middleware('erp.permission:hr.leave.approve');
            Route::apiResource('employee-leave-days', \App\Http\Controllers\Api\V1\EmployeeLeaveDayController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:hr.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:hr.manage']);
            Route::apiResource('attendance-clock-devices', \App\Http\Controllers\Api\V1\AttendanceClockDeviceController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:hr.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:hr.manage']);
            Route::apiResource('organization-holidays', \App\Http\Controllers\Api\V1\OrganizationHolidayController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:hr.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:hr.manage']);
            Route::get('employee-attendance/day-preview', [\App\Http\Controllers\Api\V1\EmployeeAttendanceController::class, 'dayPreview'])
                ->middleware('erp.permission:hr.view');
            Route::apiResource('payroll-deduction-types', \App\Http\Controllers\Api\V1\PayrollDeductionTypeController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:hr.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:hr.manage']);
            Route::apiResource('employee-deductions', \App\Http\Controllers\Api\V1\EmployeeDeductionController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:hr.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:hr.manage']);
            Route::apiResource('employee-allowances', \App\Http\Controllers\Api\V1\EmployeeAllowanceController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:hr.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:hr.manage']);
            Route::apiResource('employee-overtime', \App\Http\Controllers\Api\V1\EmployeeOvertimeController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:hr.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:hr.manage']);
            Route::post('employee-cash-advances/{id}/approve', [\App\Http\Controllers\Api\V1\EmployeeCashAdvanceController::class, 'approve'])
                ->middleware('erp.permission:hr.cash_advances.approve');
            Route::post('employee-cash-advances/{id}/reject', [\App\Http\Controllers\Api\V1\EmployeeCashAdvanceController::class, 'reject'])
                ->middleware('erp.permission:hr.cash_advances.approve');
            Route::apiResource('employee-cash-advances', \App\Http\Controllers\Api\V1\EmployeeCashAdvanceController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:hr.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:hr.manage']);
            Route::apiResource('employee-attendance', \App\Http\Controllers\Api\V1\EmployeeAttendanceController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:hr.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:hr.manage']);
            Route::post('pay-periods/ensure-runnable', [\App\Http\Controllers\Api\V1\PayPeriodController::class, 'ensureRunnable'])
                ->middleware('erp.permission:hr.manage');
            Route::apiResource('pay-periods', \App\Http\Controllers\Api\V1\PayPeriodController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:hr.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:hr.manage']);
            Route::apiResource('payroll-runs', \App\Http\Controllers\Api\V1\PayrollRunController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:hr.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:hr.manage']);
            Route::apiResource('payroll-lines', \App\Http\Controllers\Api\V1\PayrollLineController::class)
                ->middleware('erp.permission:hr.view');
        });

        Route::middleware(['erp.module:distribution'])->group(function () {
            Route::get('route-schedules/for-date', [\App\Http\Controllers\Api\V1\RouteScheduleController::class, 'forDate'])
                ->middleware('erp.permission:fulfillment.view');
            Route::apiResource('route-schedules', \App\Http\Controllers\Api\V1\RouteScheduleController::class)
                ->middlewareFor(['index', 'show', 'forDate'], ['erp.permission:fulfillment.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:fulfillment.manage']);

            Route::post('dispatch-trips/{trip}/reorder-stops', [\App\Http\Controllers\Api\V1\DispatchTripController::class, 'reorderStops'])
                ->middleware('erp.permission:fulfillment.manage');
            Route::post('dispatch-trips/{trip}/assign-orders', [\App\Http\Controllers\Api\V1\DispatchTripController::class, 'assignOrders'])
                ->middleware('erp.permission:fulfillment.manage');
            Route::get('dispatch-trips/{trip}/loading-list', [\App\Http\Controllers\Api\V1\DispatchTripController::class, 'loadingList'])
                ->middleware('erp.permission:fulfillment.view');
            Route::get('dispatch-trips/{trip}/reconciliation', [\App\Http\Controllers\Api\V1\DispatchTripController::class, 'reconciliation'])
                ->middleware('erp.permission:fulfillment.view');
            Route::post('dispatch-trips/{trip}/loading-list/lock', [\App\Http\Controllers\Api\V1\DispatchTripController::class, 'lockLoadingList'])
                ->middleware('erp.permission:fulfillment.manage');
            Route::post('dispatch-trips/{trip}/start', [\App\Http\Controllers\Api\V1\DispatchTripController::class, 'start'])
                ->middleware('erp.permission:fulfillment.manage');
            Route::post('dispatch-trips/{trip}/complete', [\App\Http\Controllers\Api\V1\DispatchTripController::class, 'complete'])
                ->middleware('erp.permission:fulfillment.manage');
            Route::post('dispatch-trips/{trip}/settle', [\App\Http\Controllers\Api\V1\DispatchTripController::class, 'settle'])
                ->middleware('erp.permission:fulfillment.manage');
            Route::post('dispatch-trips/{trip}/cancel', [\App\Http\Controllers\Api\V1\DispatchTripController::class, 'cancel'])
                ->middleware('erp.permission:fulfillment.manage');
            Route::apiResource('dispatch-trips', \App\Http\Controllers\Api\V1\DispatchTripController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:fulfillment.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:fulfillment.manage']);

            Route::apiResource('pod-records', \App\Http\Controllers\Api\V1\PodRecordController::class)
                ->only(['index', 'show'])
                ->middleware('erp.permission:fulfillment.view');
            Route::get('pod-records/{podRecord}/photo/file', [\App\Http\Controllers\Api\V1\PodRecordController::class, 'photoFile'])
                ->middleware('erp.permission:fulfillment.view');
            Route::get('pod-records/{podRecord}/signature/file', [\App\Http\Controllers\Api\V1\PodRecordController::class, 'signatureFile'])
                ->middleware('erp.permission:fulfillment.view');

            Route::get('drivers/{driver}/deliveries', [\App\Http\Controllers\Api\V1\DriverController::class, 'deliveries'])
                ->middleware('erp.permission:fulfillment.view');
            Route::apiResource('drivers', \App\Http\Controllers\Api\V1\DriverController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:fulfillment.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:fulfillment.manage']);
            Route::get('vehicles/{vehicle}/deliveries', [\App\Http\Controllers\Api\V1\VehicleController::class, 'deliveries'])
                ->middleware('erp.permission:fulfillment.view');
            Route::apiResource('vehicles', \App\Http\Controllers\Api\V1\VehicleController::class)
                ->middlewareFor(['index', 'show'], ['erp.permission:fulfillment.view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:fulfillment.manage']);
        });

        require __DIR__.'/api_operations.php';
    });
});
