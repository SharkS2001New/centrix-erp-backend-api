<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\TillController;
use App\Http\Controllers\Api\V1\TillFloatSessionController;
use App\Http\Controllers\Api\V1\SupplierController;
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
use App\Http\Controllers\Api\V1\SupplierReturnController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\SaleController;
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
use App\Http\Controllers\Api\V1\ExpenseGroupController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\KraResponseController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\SystemSettingController;
use App\Http\Controllers\Api\V1\ErpCapabilitiesController;
use App\Http\Controllers\Api\V1\StockReservationController;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::get('erp/capabilities', [ErpCapabilitiesController::class, 'show']);
        Route::get('erp/profiles', [ErpCapabilitiesController::class, 'profiles'])
            ->middleware(['erp.module:admin', 'erp.permission:admin.manage']);

        Route::apiResource('organizations', OrganizationController::class);
        Route::apiResource('branches', BranchController::class);
        Route::apiResource('roles', RoleController::class);
        Route::apiResource('permissions', PermissionController::class);
        Route::apiResource('users', UserController::class);

        Route::middleware('erp.module:sales.pos')->group(function () {
            Route::apiResource('tills', TillController::class);
            Route::apiResource('till-float-sessions', TillFloatSessionController::class);
        });
        Route::apiResource('suppliers', SupplierController::class);
        Route::apiResource('vats', VatController::class);
        Route::apiResource('uoms', UomController::class);
        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('sub-categories', SubCategoryController::class);
        Route::apiResource('products', ProductController::class);
        Route::apiResource('retail-package-settings', RetailPackageSettingController::class);
        Route::apiResource('price-history', PriceHistoryController::class);
        Route::apiResource('routes', RouteModelController::class);
        Route::get('customers/{customer}/sales', [CustomerController::class, 'sales']);
        Route::apiResource('customers', CustomerController::class);
        Route::apiResource('current-stock', CurrentStockController::class);
        Route::apiResource('inventory-transactions', InventoryTransactionController::class);
        Route::apiResource('damages', DamageController::class);
        Route::apiResource('stock-receipts', StockReceiptController::class);
        Route::apiResource('supplier-returns', SupplierReturnController::class);
        Route::apiResource('payment-methods', PaymentMethodController::class);
        Route::apiResource('sales', SaleController::class);
        Route::apiResource('sale-items', SaleItemController::class);
        Route::apiResource('sale-payments', SalePaymentController::class);
        Route::apiResource('temporary-carts', TemporaryCartController::class);
        Route::apiResource('cart-lines', CartLineController::class);
        Route::apiResource('stock-reservations', StockReservationController::class);
        Route::apiResource('customer-invoices', CustomerInvoiceController::class);
        Route::apiResource('customer-invoice-payments', CustomerInvoicePaymentController::class);
        Route::apiResource('lpo-statuses', LpoStatusController::class);
        Route::apiResource('lpo-mst', LpoMstController::class);
        Route::apiResource('lpo-txn', LpoTxnController::class);
        Route::apiResource('lpo-attachments', LpoAttachmentController::class);
        Route::apiResource('lpo-supplier-invoices', LpoSupplierInvoiceController::class);
        Route::apiResource('returns', ReturnRecordController::class);
        Route::apiResource('expense-groups', ExpenseGroupController::class);
        Route::apiResource('expenses', ExpenseController::class);
        Route::apiResource('kra-responses', KraResponseController::class);
        Route::apiResource('audit-logs', AuditLogController::class);
        Route::apiResource('system-settings', SystemSettingController::class);

        // HR / Accounting / Fulfillment CRUD
        Route::apiResource('chart-of-accounts', \App\Http\Controllers\Api\V1\ChartOfAccountController::class);
        Route::apiResource('journal-entries', \App\Http\Controllers\Api\V1\JournalEntryController::class);
        Route::apiResource('journal-entry-lines', \App\Http\Controllers\Api\V1\JournalEntryLineController::class);
        Route::apiResource('departments', \App\Http\Controllers\Api\V1\DepartmentController::class);
        Route::get('employees/{employee}/payroll-lines', [\App\Http\Controllers\Api\V1\EmployeeController::class, 'payrollLines']);
        Route::get('employees/{employee}/bank-accounts', [\App\Http\Controllers\Api\V1\EmployeeBankAccountController::class, 'index']);
        Route::post('employees/{employee}/bank-accounts', [\App\Http\Controllers\Api\V1\EmployeeBankAccountController::class, 'store']);
        Route::put('employees/{employee}/bank-accounts/{bankAccount}', [\App\Http\Controllers\Api\V1\EmployeeBankAccountController::class, 'update']);
        Route::delete('employees/{employee}/bank-accounts/{bankAccount}', [\App\Http\Controllers\Api\V1\EmployeeBankAccountController::class, 'destroy']);
        Route::get('employees/{employee}/emergency-contacts', [\App\Http\Controllers\Api\V1\EmployeeEmergencyContactController::class, 'index']);
        Route::post('employees/{employee}/emergency-contacts', [\App\Http\Controllers\Api\V1\EmployeeEmergencyContactController::class, 'store']);
        Route::put('employees/{employee}/emergency-contacts/{contact}', [\App\Http\Controllers\Api\V1\EmployeeEmergencyContactController::class, 'update']);
        Route::delete('employees/{employee}/emergency-contacts/{contact}', [\App\Http\Controllers\Api\V1\EmployeeEmergencyContactController::class, 'destroy']);
        Route::get('employees/{employee}/next-of-kin', [\App\Http\Controllers\Api\V1\EmployeeNextOfKinController::class, 'show']);
        Route::put('employees/{employee}/next-of-kin', [\App\Http\Controllers\Api\V1\EmployeeNextOfKinController::class, 'upsert']);
        Route::delete('employees/{employee}/next-of-kin', [\App\Http\Controllers\Api\V1\EmployeeNextOfKinController::class, 'destroy']);
        Route::apiResource('employees', \App\Http\Controllers\Api\V1\EmployeeController::class);
        Route::apiResource('pay-periods', \App\Http\Controllers\Api\V1\PayPeriodController::class);
        Route::apiResource('payroll-runs', \App\Http\Controllers\Api\V1\PayrollRunController::class);
        Route::apiResource('payroll-lines', \App\Http\Controllers\Api\V1\PayrollLineController::class);
        Route::get('drivers/{driver}/deliveries', [\App\Http\Controllers\Api\V1\DriverController::class, 'deliveries']);
        Route::apiResource('drivers', \App\Http\Controllers\Api\V1\DriverController::class);
        Route::get('vehicles/{vehicle}/deliveries', [\App\Http\Controllers\Api\V1\VehicleController::class, 'deliveries']);
        Route::apiResource('vehicles', \App\Http\Controllers\Api\V1\VehicleController::class);
        Route::apiResource('stock-take-sessions', \App\Http\Controllers\Api\V1\StockTakeSessionController::class);
        Route::apiResource('stock-take-lines', \App\Http\Controllers\Api\V1\StockTakeLineController::class);

        require __DIR__.'/api_operations.php';
    });
});
