<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ErpSettingsController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationProvisionController;
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
use App\Http\Controllers\Api\V1\VoucherController;
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
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
    // Safaricom rejects callback URLs containing the word "mpesa" in the path.
    Route::post('payments/stk/callback', [MpesaPaymentController::class, 'stkCallback']);
    Route::post('payments/c2b/validation', [MpesaPaymentController::class, 'validationRequest']);
    Route::post('payments/c2b/confirmation', [MpesaPaymentController::class, 'c2bConfirmation']);
    Route::get('accounting/quickbooks/callback', [\App\Http\Controllers\Api\V1\Operations\ExternalAccountingController::class, 'quickBooksCallback']);

    Route::middleware(['auth:sanctum', 'erp.tenant'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);
        Route::get('auth/memberships', [AuthController::class, 'memberships']);
        Route::post('auth/switch-organization', [AuthController::class, 'switchOrganization']);

        Route::get('erp/capabilities', [ErpCapabilitiesController::class, 'show']);
        Route::get('erp/profiles', [ErpCapabilitiesController::class, 'profiles'])
            ->middleware(['erp.module:admin', 'erp.permission:admin.manage']);
        Route::get('erp/settings/sales', [ErpSettingsController::class, 'sales'])
            ->middleware(['erp.module:admin', 'erp.permission:admin.manage']);
        Route::patch('erp/settings/sales', [ErpSettingsController::class, 'updateSales'])
            ->middleware(['erp.module:admin', 'erp.permission:admin.manage']);
        Route::get('erp/settings/inventory', [ErpSettingsController::class, 'inventory'])
            ->middleware(['erp.module:admin', 'erp.permission:admin.manage']);
        Route::patch('erp/settings/inventory', [ErpSettingsController::class, 'updateInventory'])
            ->middleware(['erp.module:admin', 'erp.permission:admin.manage']);
        Route::get('erp/settings/finance', [ErpSettingsController::class, 'finance'])
            ->middleware(['erp.module:admin', 'erp.permission:admin.manage']);
        Route::patch('erp/settings/finance', [ErpSettingsController::class, 'updateFinance'])
            ->middleware(['erp.module:admin', 'erp.permission:admin.manage']);

        Route::get('admin/organizations', [OrganizationProvisionController::class, 'index'])
            ->middleware(['erp.super_admin', 'erp.org_provisioning']);
        Route::post('admin/organizations/provision', [OrganizationProvisionController::class, 'store'])
            ->middleware(['erp.super_admin', 'erp.org_provisioning']);

        Route::apiResource('organizations', OrganizationController::class);
        Route::apiResource('branches', BranchController::class);
        Route::get('roles/permissions/matrix', [RoleController::class, 'permissionMatrix']);
        Route::get('roles/{role}/permissions', [RoleController::class, 'permissions']);
        Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions']);
        Route::apiResource('roles', RoleController::class);
        Route::apiResource('permissions', PermissionController::class);
        Route::apiResource('users', UserController::class)->middleware('erp.admin');
        Route::get('users/{user}/permissions', [UserController::class, 'permissions'])->middleware('erp.admin');
        Route::put('users/{user}/permissions', [UserController::class, 'syncPermissions'])->middleware('erp.admin');
        Route::post('users/{user}/memberships', [UserController::class, 'addMembership'])->middleware('erp.admin');

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
        Route::get('customers/{customer}/shop-image/file', [CustomerController::class, 'shopImageFile']);
        Route::post('customers/{customer}/shop-image', [CustomerController::class, 'uploadShopImage']);
        Route::delete('customers/{customer}/shop-image', [CustomerController::class, 'deleteShopImage']);
        Route::apiResource('customers', CustomerController::class);
        Route::apiResource('current-stock', CurrentStockController::class);
        Route::apiResource('inventory-transactions', InventoryTransactionController::class);
        Route::apiResource('damages', DamageController::class);
        Route::apiResource('stock-receipts', StockReceiptController::class);
        Route::apiResource('supplier-returns', SupplierReturnController::class);
        Route::apiResource('payment-methods', PaymentMethodController::class);
        Route::apiResource('vouchers', VoucherController::class);
        Route::apiResource('loyalty-cards', \App\Http\Controllers\Api\V1\LoyaltyCardController::class);
        Route::apiResource('sales', SaleController::class);
        Route::apiResource('sale-items', SaleItemController::class);
        Route::apiResource('sale-payments', SalePaymentController::class);
        Route::apiResource('temporary-carts', TemporaryCartController::class);
        Route::apiResource('cart-lines', CartLineController::class);
        Route::apiResource('stock-reservations', StockReservationController::class);
        Route::apiResource('customer-invoices', CustomerInvoiceController::class);
        Route::apiResource('customer-invoice-payments', CustomerInvoicePaymentController::class);
        Route::apiResource('lpo-statuses', LpoStatusController::class);
        Route::get('lpo-mst/dashboard', [LpoMstController::class, 'dashboard']);
        Route::get('lpo-mst/{lpoNo}/summary', [LpoMstController::class, 'summary']);
        Route::apiResource('lpo-mst', LpoMstController::class);
        Route::apiResource('lpo-txn', LpoTxnController::class);
        Route::apiResource('lpo-attachments', LpoAttachmentController::class);
        Route::apiResource('lpo-supplier-invoices', LpoSupplierInvoiceController::class);
        Route::apiResource('returns', ReturnRecordController::class);
        Route::middleware('erp.permission:inventory.manage')->group(function () {
            Route::get('sales/{saleId}/return-lines', [CustomerReturnController::class, 'saleLines']);
            Route::post('customer-returns/{id}/approve', [CustomerReturnController::class, 'approve']);
            Route::post('customer-returns/{id}/reject', [CustomerReturnController::class, 'reject']);
            Route::apiResource('customer-returns', CustomerReturnController::class);
        });
        Route::apiResource('expense-groups', ExpenseGroupController::class);
        Route::apiResource('expenses', ExpenseController::class);
        Route::apiResource('kra-responses', KraResponseController::class);
        Route::apiResource('audit-logs', AuditLogController::class);
        Route::apiResource('system-settings', SystemSettingController::class);

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
        Route::apiResource('departments', \App\Http\Controllers\Api\V1\DepartmentController::class);
        Route::get('employees/{employee}/photo/file', [\App\Http\Controllers\Api\V1\EmployeeController::class, 'photoFile']);
        Route::post('employees/{employee}/photo', [\App\Http\Controllers\Api\V1\EmployeeController::class, 'uploadPhoto']);
        Route::delete('employees/{employee}/photo', [\App\Http\Controllers\Api\V1\EmployeeController::class, 'deletePhoto']);
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
        Route::get('employees/{employee}/documents', [\App\Http\Controllers\Api\V1\EmployeeDocumentController::class, 'index']);
        Route::post('employees/{employee}/documents', [\App\Http\Controllers\Api\V1\EmployeeDocumentController::class, 'store']);
        Route::get('employees/{employee}/documents/{document}/file', [\App\Http\Controllers\Api\V1\EmployeeDocumentController::class, 'file']);
        Route::get('employees/{employee}/documents/{document}', [\App\Http\Controllers\Api\V1\EmployeeDocumentController::class, 'show']);
        Route::put('employees/{employee}/documents/{document}', [\App\Http\Controllers\Api\V1\EmployeeDocumentController::class, 'update']);
        Route::delete('employees/{employee}/documents/{document}', [\App\Http\Controllers\Api\V1\EmployeeDocumentController::class, 'destroy']);
        Route::apiResource('employees', \App\Http\Controllers\Api\V1\EmployeeController::class);
        Route::apiResource('positions', \App\Http\Controllers\Api\V1\PositionController::class);
        Route::apiResource('work-shifts', \App\Http\Controllers\Api\V1\WorkShiftController::class);
        Route::get('organization-leave-settings', [\App\Http\Controllers\Api\V1\OrganizationLeaveSettingsController::class, 'show']);
        Route::put('organization-leave-settings', [\App\Http\Controllers\Api\V1\OrganizationLeaveSettingsController::class, 'update']);
        Route::get('employee-leave-balances', [\App\Http\Controllers\Api\V1\EmployeeLeaveBalanceController::class, 'index']);
        Route::post('employee-leave-balances/allocate-off-days', [\App\Http\Controllers\Api\V1\EmployeeLeaveBalanceController::class, 'allocateOffDays']);
        Route::put('employees/{employee}/leave-balances', [\App\Http\Controllers\Api\V1\EmployeeLeaveBalanceController::class, 'update']);
        Route::get('employees/{employee}/leave-balances', [\App\Http\Controllers\Api\V1\EmployeeLeaveDayController::class, 'balances']);
        Route::get('employee-leave-days/calculate', [\App\Http\Controllers\Api\V1\EmployeeLeaveDayController::class, 'calculate']);
        Route::apiResource('employee-leave-days', \App\Http\Controllers\Api\V1\EmployeeLeaveDayController::class);
        Route::apiResource('attendance-clock-devices', \App\Http\Controllers\Api\V1\AttendanceClockDeviceController::class);
        Route::apiResource('organization-holidays', \App\Http\Controllers\Api\V1\OrganizationHolidayController::class);
        Route::get('employee-attendance/day-preview', [\App\Http\Controllers\Api\V1\EmployeeAttendanceController::class, 'dayPreview']);
        Route::apiResource('payroll-deduction-types', \App\Http\Controllers\Api\V1\PayrollDeductionTypeController::class);
        Route::apiResource('employee-deductions', \App\Http\Controllers\Api\V1\EmployeeDeductionController::class);
        Route::apiResource('employee-allowances', \App\Http\Controllers\Api\V1\EmployeeAllowanceController::class);
        Route::apiResource('employee-overtime', \App\Http\Controllers\Api\V1\EmployeeOvertimeController::class);
        Route::apiResource('employee-cash-advances', \App\Http\Controllers\Api\V1\EmployeeCashAdvanceController::class);
        Route::apiResource('employee-attendance', \App\Http\Controllers\Api\V1\EmployeeAttendanceController::class);
        Route::post('pay-periods/ensure-runnable', [\App\Http\Controllers\Api\V1\PayPeriodController::class, 'ensureRunnable']);
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
