<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\DamageController;
use App\Http\Controllers\Api\V1\Operations\CartOperationsController;
use App\Http\Controllers\Api\V1\Operations\CheckoutController;
use App\Http\Controllers\Api\V1\Operations\OrderWorkflowController;
use App\Http\Controllers\Api\V1\Operations\StockOperationsController;
use App\Http\Controllers\Api\V1\Operations\StockTransferController;
use App\Http\Controllers\Api\V1\Operations\BranchStockTransferController;
use App\Http\Controllers\Api\V1\Operations\LpoReceiveController;
use App\Http\Controllers\Api\V1\Operations\PaymentOperationsController;
use App\Http\Controllers\Api\V1\Operations\TillOperationsController;
use App\Http\Controllers\Api\V1\Operations\ReferencePickerController;
use App\Http\Controllers\Api\V1\Operations\ReportController;
use App\Http\Controllers\Api\V1\Operations\LegacyArchiveController;
use App\Http\Controllers\Api\V1\Operations\HrReportController;
use App\Http\Controllers\Api\V1\Operations\ReportBuilderController;
use App\Http\Controllers\Api\V1\Operations\ExternalAccountingController;
use App\Http\Controllers\Api\V1\Operations\FiscalPeriodController;
use App\Http\Controllers\Api\V1\Operations\JournalOperationsController;
use App\Http\Controllers\Api\V1\Operations\AccountingSettingsController;
use App\Http\Controllers\Api\V1\Operations\YearEndCloseController;
use App\Http\Controllers\Api\V1\Operations\AccountingReportController;
use App\Http\Controllers\Api\V1\Operations\BankReconciliationController;
use App\Http\Controllers\Api\V1\Operations\AttendanceClockController;
use App\Http\Controllers\Api\V1\Operations\AttendanceMissedPunchesController;
use App\Http\Controllers\Api\V1\Operations\CompanyMobileAttendanceController;
use App\Http\Controllers\Api\V1\Operations\CompanyPremisesController;
use App\Http\Controllers\Api\V1\FieldRepHrLinkageController;
use App\Http\Controllers\Api\V1\MobileFieldAttendanceController;
use App\Http\Controllers\Api\V1\MobileDriverAttendanceAdminController;
use App\Http\Controllers\Api\V1\Operations\PayrollOperationsController;
use App\Http\Controllers\Api\V1\Operations\ReturnOperationsController;
use App\Http\Controllers\Api\V1\Operations\MpesaReconciliationController;
use App\Http\Controllers\Api\V1\Operations\MpesaPaymentController;
use App\Http\Controllers\Api\V1\Operations\KraProductRegistrationController;
use App\Http\Controllers\Api\V1\Operations\KraOperationsController;
use App\Http\Controllers\Api\V1\KraResponseController;
use App\Http\Controllers\Api\V1\Operations\MobileAttendanceController;
use App\Http\Controllers\Api\V1\Operations\MobileDriverController;
use App\Http\Controllers\Api\V1\Operations\MobileDriverAttendanceController;
use App\Http\Controllers\Api\V1\Operations\MobileManagerAdminController;
use App\Http\Controllers\Api\V1\Operations\MobileManagerController;
use App\Http\Controllers\Api\V1\Operations\MobileSalesController;

Route::middleware('auth:sanctum')->group(function () {

    // ---- Centrix Manager app ----
    Route::middleware(['erp.manager_app', 'erp.permission:mobile_manager.app.access'])->prefix('manager')->group(function () {
        Route::get('dashboard', [MobileManagerController::class, 'dashboard'])
            ->middleware('erp.permission:mobile_manager.dashboard.view|mobile_manager.approvals.view');
        Route::get('branches', [MobileManagerController::class, 'branches']);
        Route::get('reports/catalog', [MobileManagerController::class, 'reportsCatalog'])
            ->middleware('erp.permission:mobile_manager.reports.view');
        Route::post('device-tokens', [MobileManagerController::class, 'registerDeviceToken']);
        Route::delete('device-tokens', [MobileManagerController::class, 'unregisterDeviceToken']);

        Route::prefix('admin')->group(function () {
            Route::get('users', [MobileManagerAdminController::class, 'indexUsers'])
                ->middleware('erp.permission:mobile_manager.users.view');
            Route::get('users/{user}', [MobileManagerAdminController::class, 'showUser'])
                ->middleware('erp.permission:mobile_manager.users.view');
            Route::post('users', [MobileManagerAdminController::class, 'storeUser'])
                ->middleware('erp.permission:mobile_manager.users.create');
            Route::match(['put', 'patch'], 'users/{user}', [MobileManagerAdminController::class, 'updateUser'])
                ->middleware('erp.permission:mobile_manager.users.edit');
            Route::delete('users/{user}', [MobileManagerAdminController::class, 'destroyUser'])
                ->middleware('erp.permission:mobile_manager.users.delete');
            Route::get('users/{user}/permissions', [MobileManagerAdminController::class, 'userPermissions'])
                ->middleware('erp.permission:mobile_manager.users.view');
            Route::put('users/{user}/permissions', [MobileManagerAdminController::class, 'syncUserPermissions'])
                ->middleware('erp.permission:mobile_manager.users.edit');

            Route::get('roles', [MobileManagerAdminController::class, 'indexRoles'])
                ->middleware('erp.permission:mobile_manager.roles.view');
            Route::post('roles', [MobileManagerAdminController::class, 'storeRole'])
                ->middleware('erp.permission:mobile_manager.roles.edit');
            Route::delete('roles/{role}', [MobileManagerAdminController::class, 'destroyRole'])
                ->middleware('erp.permission:mobile_manager.roles.edit');
            Route::get('roles/permission-matrix', [MobileManagerAdminController::class, 'permissionMatrix'])
                ->middleware('erp.permission:mobile_manager.roles.view');
            Route::get('roles/{role}/permissions', [MobileManagerAdminController::class, 'rolePermissions'])
                ->middleware('erp.permission:mobile_manager.roles.view');
            Route::put('roles/{role}/permissions', [MobileManagerAdminController::class, 'syncRolePermissions'])
                ->middleware('erp.permission:mobile_manager.roles.edit');

            Route::get('branches', [MobileManagerAdminController::class, 'branches'])
                ->middleware('erp.permission:mobile_manager.users.view|mobile_manager.users.create|mobile_manager.users.edit|mobile_manager.roles.view');
        });
    });

    // ---- Mobile field sales ----
    Route::middleware(['erp.module:sales.mobile', 'erp.mobile_sales', 'erp.permission:sales.create'])->prefix('mobile')->group(function () {
        Route::get('dashboard', [MobileSalesController::class, 'dashboard']);
        Route::get('reconciliation', [MobileSalesController::class, 'reconciliation']);
        Route::get('routes', [MobileSalesController::class, 'indexRoutes']);
        Route::get('orders', [MobileSalesController::class, 'index']);
        Route::get('orders/{saleId}', [MobileSalesController::class, 'show']);
        Route::patch('orders/{saleId}/editable-lines', [MobileSalesController::class, 'updateEditableLines']);
        Route::post('orders/{saleId}/returns', [MobileSalesController::class, 'storeReturn']);
        Route::post('orders/{saleId}/payments', [MobileSalesController::class, 'storePayment']);
        Route::get('customers', [MobileSalesController::class, 'indexCustomers']);
        Route::get('customers/{customerNum}', [MobileSalesController::class, 'showCustomer']);
        Route::post('customers', [MobileSalesController::class, 'storeCustomer']);
        Route::put('customers/{customerNum}', [MobileSalesController::class, 'updateCustomer']);
        Route::get('attendance/session', [MobileAttendanceController::class, 'session']);
        Route::get('attendance/summary', [MobileAttendanceController::class, 'summary']);
        Route::post('attendance/sign-in', [MobileAttendanceController::class, 'signIn']);
        Route::post('attendance/suspend', [MobileAttendanceController::class, 'suspend']);
        Route::post('attendance/resume', [MobileAttendanceController::class, 'resume']);
        Route::post('attendance/sign-out', [MobileAttendanceController::class, 'signOut']);
        Route::post('device-tokens', [MobileSalesController::class, 'registerDeviceToken']);
        Route::delete('device-tokens', [MobileSalesController::class, 'unregisterDeviceToken']);
    });

    Route::middleware(['erp.module:sales.mobile', 'erp.module:distribution', 'erp.mobile_driver', 'erp.permission:driver.mobile'])->prefix('mobile/driver')->group(function () {
        Route::get('attendance/session', [MobileDriverAttendanceController::class, 'session']);
        Route::get('attendance/summary', [MobileDriverAttendanceController::class, 'summary']);
        Route::post('attendance/sign-in', [MobileDriverAttendanceController::class, 'signIn']);
        Route::post('attendance/suspend', [MobileDriverAttendanceController::class, 'suspend']);
        Route::post('attendance/resume', [MobileDriverAttendanceController::class, 'resume']);
        Route::post('attendance/sign-out', [MobileDriverAttendanceController::class, 'signOut']);
        Route::get('trips/today', [MobileDriverController::class, 'todayTrips']);
        Route::get('trips/upcoming', [MobileDriverController::class, 'upcomingTrips']);
        Route::get('trips/by-date', [MobileDriverController::class, 'tripsByDate']);
        Route::get('trips/{tripId}', [MobileDriverController::class, 'showTrip']);
        Route::get('trips/{tripId}/stops', [MobileDriverController::class, 'tripStops']);
        Route::post('trips/{tripId}/settle', [MobileDriverController::class, 'settleTrip']);
        Route::get('stops/{saleId}', [MobileDriverController::class, 'showStop']);
        Route::post('stops/{saleId}/deliver', [MobileDriverController::class, 'deliverStop']);
    });

    // Reference pickers for forms/reports — org-scoped, no resource-view permission required.
    Route::prefix('reference')->group(function () {
        Route::get('users', [ReferencePickerController::class, 'users']);
        Route::get('vats', [ReferencePickerController::class, 'vats']);
        Route::get('categories', [ReferencePickerController::class, 'categories']);
        Route::get('sub-categories', [ReferencePickerController::class, 'subCategories']);
        Route::get('uoms', [ReferencePickerController::class, 'uoms']);
        Route::get('suppliers', [ReferencePickerController::class, 'suppliers']);
        Route::get('routes', [ReferencePickerController::class, 'routes']);
        Route::get('payment-methods', [ReferencePickerController::class, 'paymentMethods']);
    });

    // ---- Sales ----
    Route::middleware('erp.permission:sales.create|pos.checkout.create|pos.terminal.view|pos.till_management.create')->prefix('sales')->group(function () {
        Route::post('carts', [CartOperationsController::class, 'store']);
        Route::get('carts/{cartId}', [CartOperationsController::class, 'show']);
        Route::patch('carts/{cartId}', [CartOperationsController::class, 'update']);
        Route::post('carts/{cartId}/discount-requests', [CartOperationsController::class, 'requestDiscount']);
        Route::post('carts/{cartId}/lines', [CartOperationsController::class, 'addLine']);
        Route::put('carts/{cartId}/lines', [CartOperationsController::class, 'replaceLines']);
        Route::patch('carts/{cartId}/lines/{lineRef}', [CartOperationsController::class, 'updateLine']);
        Route::delete('carts/{cartId}/lines/{lineRef}', [CartOperationsController::class, 'deleteLine']);
        Route::delete('carts/{cartId}/lines', [CartOperationsController::class, 'clear']);
        Route::get('customers/lookup', [CartOperationsController::class, 'lookupCustomers']);
        Route::get('orders/same-day-for-customer', [CartOperationsController::class, 'sameDayForCustomer']);
        Route::get('loyalty-cards/lookup', [CartOperationsController::class, 'lookupLoyaltyCard']);
        Route::post('carts/{cartId}/loyalty', [CartOperationsController::class, 'attachLoyaltyCard']);
        Route::post('carts/{cartId}/payment/voucher', [CartOperationsController::class, 'applyVoucherPayment']);
        Route::post('carts/{cartId}/payment/points', [CartOperationsController::class, 'applyPointsPayment']);
        Route::patch('carts/{cartId}/payment/extras', [CartOperationsController::class, 'updateCartPaymentExtras']);
        Route::post('carts/{cartId}/payment/mpesa/stk-push', [MpesaPaymentController::class, 'stkPush']);
        Route::get('carts/{cartId}/payment/mpesa/status', [MpesaPaymentController::class, 'paymentStatus']);
        Route::get('carts/{cartId}/payment/mpesa/incoming', [MpesaPaymentController::class, 'lookupIncomingPayments']);
        Route::post('carts/{cartId}/payment/mpesa/apply', [MpesaPaymentController::class, 'applyIncomingPayment']);
        Route::post('carts/{cartId}/payment/mpesa/skip', [MpesaPaymentController::class, 'skipIncomingPayment']);
        Route::delete('carts/{cartId}/payment', [CartOperationsController::class, 'clearCartPayments']);
        Route::post('carts/{cartId}/checkout', [CheckoutController::class, 'fromCart']);
        Route::post('carts/{cartId}/checkout-quote', [CheckoutController::class, 'quoteFromCart']);
        Route::post('order-numbers/reserve', [\App\Http\Controllers\Api\V1\Operations\OrderNumberReserveController::class, 'reserve']);
        Route::post('orders/{saleId}/restore-to-cart', [CartOperationsController::class, 'restoreHeldOrder']);
        Route::post('orders/{saleId}/cancel-held', [CartOperationsController::class, 'cancelHeldOrder']);
        Route::post('orders/{saleId}/cancel', [CartOperationsController::class, 'cancelOrder']);
        Route::post('orders/{saleId}/request-cancellation', [OrderWorkflowController::class, 'requestCancellation']);
    });

    Route::middleware('erp.permission:sales.manage')->prefix('sales')->group(function () {
        Route::get('orders/{saleId}/load-weight-status', [OrderWorkflowController::class, 'loadWeightStatus']);
        Route::post('orders/{saleId}/product-weights', [OrderWorkflowController::class, 'updateOrderProductWeights']);
        Route::post('orders/{saleId}/transition', [OrderWorkflowController::class, 'transition']);
        Route::post('orders/{saleId}/pod', [\App\Http\Controllers\Api\V1\PodRecordController::class, 'storeForSale']);
    });

    Route::middleware('erp.permission:sales.orders.edit')->prefix('sales')->group(function () {
        Route::patch('orders/{saleId}/line-quantities', [\App\Http\Controllers\Api\V1\Operations\BackofficeOrderLineEditController::class, 'updateLineQuantities']);
        Route::post('orders/merge', [\App\Http\Controllers\Api\V1\Operations\SaleMergeController::class, 'merge']);
    });

    Route::post('sales/{saleId}/payments', [PaymentOperationsController::class, 'paySale'])
        ->middleware([
            'erp.module:payments,sales',
            'erp.permission:payments.manage|payments.sale_payments.create|sales.collect_payment.create',
        ]);
    Route::middleware(['erp.module:payments,sales', 'erp.permission:payments.manage'])->group(function () {
        Route::post('sales/{saleId}/convert-to-paid', [PaymentOperationsController::class, 'convertToPaid']);
        Route::post('sales/{saleId}/convert-to-unpaid', [PaymentOperationsController::class, 'convertToUnpaid']);
    });

    // ---- POS till ----
    Route::middleware(['erp.module:sales.pos', 'erp.permission:pos.till'])->prefix('pos')->group(function () {
        Route::post('sessions/open', [TillOperationsController::class, 'openSession']);
        Route::post('sessions/{sessionId}/add-float', [TillOperationsController::class, 'addFloat']);
        Route::post('sessions/{sessionId}/cash-movement', [TillOperationsController::class, 'recordCashMovement']);
        Route::post('sessions/{sessionId}/expenses', [TillOperationsController::class, 'recordSessionExpense']);
        Route::get('sessions/{sessionId}/expenses', [TillOperationsController::class, 'listSessionExpenses']);
        Route::get('expense-groups', [TillOperationsController::class, 'expenseGroups']);
        Route::post('sessions/{sessionId}/suspend', [TillOperationsController::class, 'suspendSession']);
        Route::post('sessions/{sessionId}/resume', [TillOperationsController::class, 'resumeSession']);
        Route::post('sessions/{sessionId}/handover', [TillOperationsController::class, 'handoverSession']);
        Route::post('sessions/{sessionId}/close', [TillOperationsController::class, 'closeSession']);
        Route::post('sessions/{sessionId}/reopen', [TillOperationsController::class, 'reopenSession']);
        Route::get('sessions/{sessionId}/x-report', [TillOperationsController::class, 'xReport']);
        Route::get('sessions/{sessionId}/z-report', [TillOperationsController::class, 'zReport']);
    });

    // ---- Inventory ----
    Route::middleware(['erp.module:inventory', 'erp.permission:inventory.view'])->prefix('inventory')->group(function () {
        Route::get('availability', [StockOperationsController::class, 'availability']);
    });

    Route::middleware(['erp.module:inventory', 'erp.permission:inventory.adjustments.create|inventory.manage'])
        ->prefix('inventory')
        ->group(function () {
            Route::post('adjust', [StockOperationsController::class, 'adjust']);
            Route::post('adjust/request', [StockOperationsController::class, 'requestAdjust']);
        });

    Route::middleware(['erp.module:inventory', 'erp.permission:inventory.damages.create|inventory.manage'])
        ->post('damages/request', [DamageController::class, 'requestStore']);

    Route::middleware(['erp.module:inventory', 'erp.permission:inventory.manage'])->prefix('inventory')->group(function () {
        Route::post('transfer', [StockTransferController::class, 'store']);
        Route::post('transfer/request', [StockTransferController::class, 'requestTransfer']);
        Route::get('branch-transfers', [BranchStockTransferController::class, 'index']);
        Route::post('branch-transfer', [BranchStockTransferController::class, 'store']);
        Route::post('receive', [LpoReceiveController::class, 'store']);
        Route::post('returns', [ReturnOperationsController::class, 'store']);
    });

    Route::middleware(['erp.module:inventory', 'erp.permission:inventory.stock_take.create|inventory.manage'])
        ->prefix('inventory')
        ->group(function () {
            Route::post('stock-take/{sessionId}/initialize', [\App\Http\Controllers\Api\V1\Operations\StockTakeOperationsController::class, 'initialize']);
            Route::post('stock-take/{sessionId}/save-counts', [\App\Http\Controllers\Api\V1\Operations\StockTakeOperationsController::class, 'saveCounts']);
            Route::post('stock-take/{sessionId}/complete', [\App\Http\Controllers\Api\V1\Operations\StockTakeOperationsController::class, 'complete']);
        });

    Route::middleware(['erp.module:inventory', 'erp.permission:inventory.stock_take.reset'])
        ->prefix('inventory')
        ->group(function () {
            Route::post('stock-take/{sessionId}/reset-stocks', [\App\Http\Controllers\Api\V1\Operations\StockTakeOperationsController::class, 'resetStocks']);
        });

    // ---- Accounting ----
    Route::middleware(['erp.module:accounting', 'erp.permission:accounting.view'])->prefix('accounting')->group(function () {
        Route::get('settings', [AccountingSettingsController::class, 'show']);
        Route::get('bank-accounts', [BankReconciliationController::class, 'bankAccounts']);
        Route::get('bank-accounts/{accountId}/register', [BankReconciliationController::class, 'register']);
        Route::get('bank-reconciliations', [BankReconciliationController::class, 'index']);
        Route::get('bank-reconciliations/{reconciliationId}', [BankReconciliationController::class, 'show']);
        Route::get('mpesa-reconciliation', [MpesaReconciliationController::class, 'index']);
        Route::get('mpesa-reconciliation/{paymentId}', [MpesaReconciliationController::class, 'show']);
    });

    Route::middleware(['erp.module:accounting', 'erp.permission:accounting.manage'])->prefix('accounting')->group(function () {
        Route::patch('settings', [AccountingSettingsController::class, 'update']);
        Route::post('seed-chart-of-accounts', [AccountingSettingsController::class, 'seedChart']);
        Route::post('journal-entries', [JournalOperationsController::class, 'store']);
        Route::post('journal-entries/{entryId}/request-post', [JournalOperationsController::class, 'requestPost']);
        Route::post('journal-entries/{entryId}/post', [JournalOperationsController::class, 'post']);
        Route::post('journal-entries/{entryId}/reverse', [JournalOperationsController::class, 'reverse']);
        Route::get('fiscal-periods', [FiscalPeriodController::class, 'index']);
        Route::post('fiscal-periods', [FiscalPeriodController::class, 'store']);
        Route::post('fiscal-periods/{periodId}/close', [FiscalPeriodController::class, 'close']);
        Route::post('fiscal-periods/{periodId}/reopen', [FiscalPeriodController::class, 'reopen']);
        Route::post('year-end-close', [YearEndCloseController::class, 'store']);
        Route::get('integration/status', [ExternalAccountingController::class, 'status']);
        Route::get('quickbooks/connect-url', [ExternalAccountingController::class, 'quickBooksConnectUrl']);
        Route::get('quickbooks/accounts', [ExternalAccountingController::class, 'quickBooksAccounts']);
        Route::post('quickbooks/disconnect', [ExternalAccountingController::class, 'quickBooksDisconnect']);
        Route::get('account-mappings', [ExternalAccountingController::class, 'listMappings']);
        Route::put('account-mappings', [ExternalAccountingController::class, 'syncMappings']);
        Route::get('export-queue', [ExternalAccountingController::class, 'exportQueue']);
        Route::post('export-queue/process', [ExternalAccountingController::class, 'processExportQueue']);
        Route::post('export-queue/retry-failed', [ExternalAccountingController::class, 'retryFailedExports']);
        Route::post('bank-reconciliations', [BankReconciliationController::class, 'store']);
        Route::post('bank-reconciliations/{reconciliationId}/statement-lines', [BankReconciliationController::class, 'importStatement']);
        Route::post('bank-reconciliations/{reconciliationId}/matches', [BankReconciliationController::class, 'applyMatch']);
        Route::delete('bank-reconciliations/{reconciliationId}/matches/{matchId}', [BankReconciliationController::class, 'removeMatch']);
        Route::post('bank-reconciliations/{reconciliationId}/statement-lines/{statementLineId}/exclude', [BankReconciliationController::class, 'excludeStatementLine']);
        Route::post('bank-reconciliations/{reconciliationId}/clear-book-item', [BankReconciliationController::class, 'clearBookItem']);
        Route::post('bank-reconciliations/{reconciliationId}/adjustment', [BankReconciliationController::class, 'createAdjustment']);
        Route::post('bank-reconciliations/{reconciliationId}/complete', [BankReconciliationController::class, 'complete']);
        Route::delete('bank-reconciliations/{reconciliationId}', [BankReconciliationController::class, 'destroy']);
        Route::post('mpesa-reconciliation/{paymentId}/apply', [MpesaReconciliationController::class, 'apply']);
        Route::post('mpesa-reconciliation/{paymentId}/ignore', [MpesaReconciliationController::class, 'ignore']);
    });

    // ---- HR / Attendance (clock device) ----
    Route::middleware(['erp.module:hr_payroll'])->prefix('attendance')->group(function () {
        Route::middleware('erp.permission:hr.manage|admin.manage')->group(function () {
            Route::post('clock-punch', [AttendanceClockController::class, 'punch']);
            Route::post('clock-in', [AttendanceClockController::class, 'clockIn']);
            Route::post('clock-out', [AttendanceClockController::class, 'clockOut']);
            Route::delete('clock-sessions/{session}', [AttendanceClockController::class, 'destroySession']);
            Route::patch('clock-sessions/{session}', [AttendanceClockController::class, 'updateSession']);
            Route::post('clock-sessions/day-times', [AttendanceClockController::class, 'syncDayPunchTimes']);
            Route::get('company-premises', [CompanyPremisesController::class, 'show']);
            Route::post('company-premises', [CompanyPremisesController::class, 'update']);
        });
        Route::get('clock-sessions', [AttendanceClockController::class, 'sessions'])
            ->middleware('erp.permission:hr.view');
        Route::get('missed-punches', [AttendanceMissedPunchesController::class, 'index'])
            ->middleware('erp.permission:hr.missed_punches.view|hr.duplicate_punches.view|hr.attendance.view|hr.view');
        Route::post('missed-punches/retry', [AttendanceMissedPunchesController::class, 'retry'])
            ->middleware('erp.permission:hr.manage|admin.manage');
        Route::post('missed-punches/auto-map', [AttendanceMissedPunchesController::class, 'autoMap'])
            ->middleware('erp.permission:hr.manage|admin.manage');
        Route::post('missed-punches/events/{event}/apply', [AttendanceMissedPunchesController::class, 'applyEvent'])
            ->middleware('erp.permission:hr.manage|admin.manage');
        Route::post('missed-punches/{session}/clock-out', [AttendanceMissedPunchesController::class, 'closeClockOut'])
            ->middleware('erp.permission:hr.manage|admin.manage');
        Route::post('duplicate-punches/dismiss', [AttendanceMissedPunchesController::class, 'dismissDuplicates'])
            ->middleware('erp.permission:hr.duplicate_punches.view|hr.attendance.view|hr.manage|hr.view|admin.manage');
        Route::post('sync-from-devices', [AttendanceMissedPunchesController::class, 'syncFromDevices'])
            ->middleware('erp.permission:hr.attendance.view|hr.attendance.create|hr.manage|hr.view|admin.manage');
        Route::get('company-mobile-sessions', [CompanyPremisesController::class, 'sessions'])
            ->middleware('erp.permission:hr.view');
        Route::get('field-sessions', [MobileFieldAttendanceController::class, 'index'])
            ->middleware('erp.permission:hr.attendance.view|hr.view');
        Route::get('field-rep-hr-linkage', [FieldRepHrLinkageController::class, 'index'])
            ->middleware('erp.permission:hr.attendance.view|hr.view');
        Route::get('field-sessions/{sessionId}/sign-in-photo/file', [MobileFieldAttendanceController::class, 'signInPhotoFile'])
            ->middleware('erp.permission:hr.attendance.view|hr.view');
        Route::get('field-sessions/{sessionId}/sign-out-photo/file', [MobileFieldAttendanceController::class, 'signOutPhotoFile'])
            ->middleware('erp.permission:hr.attendance.view|hr.view');
        Route::get('field-sessions/{sessionId}', [MobileFieldAttendanceController::class, 'show'])
            ->middleware('erp.permission:hr.attendance.view|hr.view');
        Route::patch('field-sessions/{sessionId}', [MobileFieldAttendanceController::class, 'update'])
            ->middleware('erp.permission:hr.manage');
        Route::post('field-sessions/{sessionId}/reopen', [MobileFieldAttendanceController::class, 'reopen'])
            ->middleware('erp.permission:hr.manage');
        Route::get('driver-sessions', [MobileDriverAttendanceAdminController::class, 'index'])
            ->middleware('erp.permission:hr.attendance.view|hr.view|fulfillment.view');
        Route::get('driver-sessions/{sessionId}', [MobileDriverAttendanceAdminController::class, 'show'])
            ->middleware('erp.permission:hr.attendance.view|hr.view|fulfillment.view');
        Route::post('driver-sessions/{sessionId}/reopen', [MobileDriverAttendanceAdminController::class, 'reopen'])
            ->middleware('erp.permission:hr.manage|fulfillment.manage');
    });

    // ---- HR / Payroll ----
    Route::middleware(['erp.module:hr_payroll'])->prefix('payroll')->group(function () {
        Route::middleware('erp.permission:hr.view')->group(function () {
            Route::get('kenya-statutory', [PayrollOperationsController::class, 'kenyaStatutory']);
            Route::get('run-schedule', [PayrollOperationsController::class, 'runSchedule']);
            Route::get('calculate', [PayrollOperationsController::class, 'calculate']);
        });
        Route::middleware('erp.permission:hr.manage')->group(function () {
            Route::post('runs/{runId}/process', [PayrollOperationsController::class, 'processRun']);
            Route::post('runs/{runId}/process-auto', [PayrollOperationsController::class, 'processAuto']);
            Route::post('runs/{runId}/approve', [PayrollOperationsController::class, 'approveRun']);
            Route::post('runs/{runId}/reject', [PayrollOperationsController::class, 'rejectRun']);
            Route::post('runs/{runId}/mark-paid', [PayrollOperationsController::class, 'markPaidRun']);
        });
        Route::middleware('erp.permission:hr.manage|hr.payroll.create|hr.payroll.view')->group(function () {
            Route::post('runs/{runId}/email-receipts', [PayrollOperationsController::class, 'emailReceipts']);
            Route::post('runs/{runId}/lines/{lineId}/email-receipt', [PayrollOperationsController::class, 'emailLineReceipt']);
        });
    });

    // ---- KRA device ----
    Route::middleware('erp.permission:products.manage|sales.create|pos.checkout.create|pos.terminal.view|pos.till_management.create')->prefix('kra')->group(function () {
        Route::post('register-products', [KraProductRegistrationController::class, 'register']);
    });

    Route::middleware('erp.permission:reports.kra_receipts.view|admin.kra_responses.view|pricing_tax.kra_invoices.view')->group(function () {
        Route::get('kra-responses', [KraResponseController::class, 'index']);
        Route::get('kra-responses/{kraResponse}', [KraResponseController::class, 'show']);
        Route::get('kra/device-status', [KraOperationsController::class, 'deviceStatus']);
        Route::post('kra-responses/{kraResponse}/retry', [KraOperationsController::class, 'retry']);
    });

    Route::middleware('erp.permission:pricing_tax.kra_invoices.credit|admin.manage')->group(function () {
        Route::post('kra-responses/{kraResponse}/credit', [KraOperationsController::class, 'credit']);
    });

    Route::middleware('erp.permission:admin.manage')->group(function () {
        Route::post('kra/device-health', [KraOperationsController::class, 'deviceHealth']);
        Route::post('kra/device-init', [KraOperationsController::class, 'deviceInit']);
        Route::post('kra/device-restart', [KraOperationsController::class, 'deviceRestart']);
    });

    // ---- Reports ----
    Route::middleware(['erp.report_module'])->prefix('reports')->group(function () {
        // Operational inventory screens (sidebar uses inventory.*) call these report endpoints.
        Route::middleware('erp.permission:reports.view|inventory.view')->group(function () {
            Route::get('stock-on-hand', [ReportController::class, 'stockOnHand']);
            Route::get('items-currently-in-stock', [ReportController::class, 'stockOnHand']);
            Route::get('low-stock', [ReportController::class, 'lowStock']);
            Route::get('stock-movement', [ReportController::class, 'stockMovement']);
            Route::get('stock-chain', [ReportController::class, 'stockChain']);
            Route::get('stock-valuation', [ReportController::class, 'stockValuation']);
            Route::get('inventory-valuation-summary', [ReportController::class, 'inventoryValuationSummary']);
            Route::get('stock-reservations', [ReportController::class, 'stockReservations']);
            Route::get('stock-receipts', [ReportController::class, 'stockReceipts']);
            Route::get('stock-transfers', [ReportController::class, 'stockTransfers']);
            Route::get('branch-stock-transfers', [ReportController::class, 'branchStockTransfers']);
            Route::get('damages', [ReportController::class, 'damages']);
            Route::get('price-list', [ReportController::class, 'priceList']);
            Route::get('product-price-sheet', [ReportController::class, 'productPriceSheet']);
        });

        // POS operational screens (e.g. /sales/end-of-day) use these report endpoints.
        Route::middleware('erp.permission:reports.view|pos.end_of_day.view|pos.till|pos.terminal.view')->group(function () {
            Route::get('eod-cashier', [ReportController::class, 'eodCashier']);
            Route::get('eod-report', [ReportController::class, 'eodReport']);
            Route::get('till-sessions', [ReportController::class, 'tillSessions']);
        });

        // Sales operational and analytics reports.
        Route::middleware('erp.permission:reports.view|sales.view')->group(function () {
            Route::get('sales-by-product', [ReportController::class, 'salesByProduct']);
            Route::get('sales-by-supplier', [ReportController::class, 'salesBySupplier']);
            Route::get('sales-by-user', [ReportController::class, 'salesByUser']);
            Route::get('sales-by-customer', [ReportController::class, 'salesByCustomer']);
            Route::get('sales-by-channel', [ReportController::class, 'salesByChannel']);
            Route::get('daily-sales', [ReportController::class, 'dailySales']);
            Route::get('sales-pipeline', [ReportController::class, 'salesPipeline']);
            Route::get('vat-collected', [ReportController::class, 'vatCollected']);
            Route::get('category-sales', [ReportController::class, 'categorySales']);
            Route::get('discount-summary', [ReportController::class, 'discountSummary']);
            Route::get('payment-collection', [ReportController::class, 'paymentCollection']);
            Route::get('returns', [ReportController::class, 'returns']);
        });

        // Distribution / fulfillment reports.
        Route::middleware('erp.permission:reports.view|fulfillment.view')->group(function () {
            Route::get('mobile-route-sales', [ReportController::class, 'routeSales']);
            Route::get('dispatch-trips', [ReportController::class, 'dispatchTrips']);
            Route::get('trip-cash-settlement', [ReportController::class, 'tripCashSettlement']);
            Route::get('pod-compliance', [ReportController::class, 'podCompliance']);
            Route::get('driver-deliveries', [ReportController::class, 'driverDeliveries']);
            Route::get('vehicle-trip-loads', [ReportController::class, 'vehicleTripLoads']);
            Route::get('driver-trip-loads', [ReportController::class, 'driverTripLoads']);
        });

        // Purchasing operational reports (e.g. open LPO from accounts payable).
        Route::middleware('erp.permission:reports.view|purchasing.view')->group(function () {
            Route::get('open-lpo', [ReportController::class, 'openLpo']);
            Route::get('purchases-by-supplier', [ReportController::class, 'purchasesBySupplier']);
            Route::get('supplier-returns', [ReportController::class, 'supplierReturns']);
        });

        Route::middleware('erp.permission:reports.view|hr.view|accounting.view|inventory.view|sales.view|purchasing.view|fulfillment.view|customers.view|pos.end_of_day.view|pos.terminal.view|admin.view|pricing_tax.kra_invoices.view|catalogue.view')->group(function () {
            Route::get('/', [ReportController::class, 'catalog']);
            Route::get('dashboard', [ReportController::class, 'dashboard']);
            Route::get('filter-cashiers', [ReportController::class, 'filterCashiers']);

            Route::middleware('erp.permission:reports.builder')->prefix('builder')->group(function () {
                Route::get('schema', [ReportBuilderController::class, 'schema']);
                Route::get('sources', [ReportBuilderController::class, 'sources']);
                Route::get('templates', [ReportBuilderController::class, 'indexTemplates']);
                Route::post('templates', [ReportBuilderController::class, 'storeTemplate']);
                Route::get('templates/{templateId}', [ReportBuilderController::class, 'showTemplate']);
                Route::patch('templates/{templateId}', [ReportBuilderController::class, 'updateTemplate']);
                Route::delete('templates/{templateId}', [ReportBuilderController::class, 'destroyTemplate']);
                Route::post('preview', [ReportBuilderController::class, 'preview']);
                Route::get('templates/{templateId}/run', [ReportBuilderController::class, 'runTemplate']);
            });

            Route::get('kra-compliance-summary', [ReportController::class, 'kraComplianceSummary']);
            Route::get('kra-receipts', [ReportController::class, 'kraReceipts'])
                ->middleware('erp.permission:reports.kra_receipts.view|pricing_tax.kra_invoices.view|admin.kra_responses.view');
            Route::get('kra-unfiscalized-sales', [ReportController::class, 'kraUnfiscalizedSales']);
            Route::get('audit-trail', [ReportController::class, 'auditTrail']);

            Route::prefix('legacy-archive')->group(function () {
                Route::get('status', [LegacyArchiveController::class, 'status']);
            });

            Route::middleware('erp.legacy_archive')->prefix('legacy-archive')->group(function () {
                Route::get('summary', [LegacyArchiveController::class, 'summary']);
                Route::get('sales/{channel}/{legacyOrderNum}', [LegacyArchiveController::class, 'showSale'])
                    ->where('channel', 'pos|mobile|debtor');
                Route::get('sales', [LegacyArchiveController::class, 'sales']);
                Route::post('sales/materialize', [LegacyArchiveController::class, 'materialize'])
                    ->middleware('erp.permission:sales.manage');
            });
        });

        Route::middleware('erp.permission:reports.view|hospitality.reports.view')->group(function () {
            Route::get('hospitality-occupancy', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReportController::class, 'occupancy']);
            Route::get('hospitality-kpi-occupancy', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReportController::class, 'kpiOccupancy']);
            Route::get('hospitality-arrivals-departures', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReportController::class, 'arrivalsDepartures']);
            Route::get('hospitality-folio-balances', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReportController::class, 'folioBalances']);
            Route::get('hospitality-room-revenue', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReportController::class, 'roomRevenue']);
            Route::get('hospitality-fnb-checks', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReportController::class, 'fnbChecks']);
            Route::get('hospitality-fnb-by-outlet', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReportController::class, 'fnbByOutlet']);
            Route::get('hospitality-fnb-by-hour', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReportController::class, 'fnbByHour']);
            Route::get('hospitality-fnb-by-category', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReportController::class, 'fnbByCategory']);
            Route::get('hospitality-open-checks', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReportController::class, 'openChecks']);
            Route::get('hospitality-voids', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReportController::class, 'voids']);
            Route::get('hospitality-manager-flash', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReportController::class, 'managerFlash']);
            Route::get('hospitality-profit-loss', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReportController::class, 'profitLoss']);
            Route::get('hospitality-eod-cashier', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReportController::class, 'eodCashier']);
            Route::get('hospitality-consumption-variance', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReportController::class, 'consumptionVariance']);
        });

        Route::get('hospitality-payments-breakdown', [ReportController::class, 'hospitalityPaymentsBreakdown'])
            ->middleware('erp.permission:reports.view|hospitality.reports.view|hospitality.payments_breakdown.view');

        Route::middleware('erp.permission:reports.view|hr.view')->group(function () {
            Route::get('payroll-summary', [ReportController::class, 'payrollSummary']);
            Route::get('leave-balance', [HrReportController::class, 'leaveBalance']);
            Route::get('attendance-register', [HrReportController::class, 'attendanceRegister']);
            Route::get('lateness-list', [HrReportController::class, 'latenessList']);
            Route::get('statutory-deductions', [HrReportController::class, 'statutoryDeductions']);
            Route::get('bank-transfer', [HrReportController::class, 'bankTransfer']);
            Route::get('nssf-remittance', [HrReportController::class, 'nssfRemittance']);
            Route::get('other-deductions', [HrReportController::class, 'otherDeductionsByPeriod']);
            Route::get('staff-turnover', [HrReportController::class, 'staffTurnover']);
            Route::get('headcount', [HrReportController::class, 'headcount']);
            Route::get('contract-expiry', [HrReportController::class, 'contractExpiry']);
            Route::get('hr-dashboard-kpi', [HrReportController::class, 'hrDashboardKpi']);
        });

        Route::middleware('erp.permission:reports.view|accounting.view|payments.sale_payments.view|pos.end_of_day.view|pos.till_management.view|pos.payments_breakdown.view')->group(function () {
            Route::get('journal-register', [ReportController::class, 'journalRegister']);
            Route::get('general-ledger', [AccountingReportController::class, 'generalLedger']);
            Route::get('trial-balance', [AccountingReportController::class, 'trialBalance']);
            Route::get('balance-sheet', [AccountingReportController::class, 'balanceSheet']);
            Route::get('profit-loss-gl', [AccountingReportController::class, 'profitLossGl']);
            Route::get('profit-loss', [ReportController::class, 'profitLoss']);
            Route::get('profit-loss-by-product', [ReportController::class, 'profitLossByProduct']);
            Route::get('cash-flow', [AccountingReportController::class, 'cashFlow']);
            Route::get('accounts-receivable', [AccountingReportController::class, 'accountsReceivable']);
            Route::get('accounts-payable', [AccountingReportController::class, 'accountsPayable']);
            Route::get('subledger-reconciliation', [AccountingReportController::class, 'subledgerReconciliation']);
            Route::get('ar-aging', [ReportController::class, 'arAging']);
            Route::get('top-debtors', [ReportController::class, 'topDebtors']);
            Route::get('invoice-payments', [ReportController::class, 'invoicePayments']);
            Route::get('credit-outstanding', [ReportController::class, 'creditOutstanding']);
            Route::get('expenses', [ReportController::class, 'expenses']);
            Route::get('payments-breakdown', [ReportController::class, 'paymentsBreakdown']);
        });

        Route::get('customers/{customerNum}/statement', [ReportController::class, 'customerStatement'])
            ->middleware('erp.permission:reports.view|customers.view');
    });

    // ---- Hotel & Bar POS (hospitality checks — not retail carts) ----
    Route::prefix('hospitality/pos')->group(function () {
        Route::get('catalog', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'catalog'])
            ->middleware('erp.permission:hotel_bar_pos.terminal.view');
        Route::patch('catalog/{product}/price', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'updateCatalogPrice'])
            ->middleware('erp.permission:hotel_bar_pos.checks.edit|products.manage')
            ->where('product', '[^/]+');
        Route::get('rooms', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'sellableRooms'])
            ->middleware('erp.permission:hotel_bar_pos.terminal.view');
        Route::get('settings', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'settings'])
            ->middleware('erp.permission:hotel_bar_pos.terminal.view');
        Route::get('checks/held', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'held'])
            ->middleware('erp.permission:hotel_bar_pos.checks.view');
        Route::get('checks/collectible', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'collectible'])
            ->middleware('erp.permission:hotel_bar_pos.checks.view');
        Route::post('check-numbers/reserve', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'reserveCheckNumbers'])
            ->middleware('erp.permission:hotel_bar_pos.checks.create');
        Route::post('checks/offline-sync', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'offlineSync'])
            ->middleware('erp.permission:hotel_bar_pos.checks.create|hotel_bar_pos.checks.edit');
        Route::post('checks', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'openCheck'])
            ->middleware('erp.permission:hotel_bar_pos.checks.create');
        Route::get('checks/{checkId}', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'showCheck'])
            ->middleware('erp.permission:hotel_bar_pos.checks.view')
            ->whereNumber('checkId');
        Route::patch('checks/{checkId}/table', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'assignTable'])
            ->middleware('erp.permission:hotel_bar_pos.checks.edit')
            ->whereNumber('checkId');
        Route::patch('checks/{checkId}/guest', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'assignGuest'])
            ->middleware('erp.permission:hotel_bar_pos.checks.edit')
            ->whereNumber('checkId');
        Route::post('checks/{checkId}/lines', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'addLine'])
            ->middleware('erp.permission:hotel_bar_pos.checks.edit')
            ->whereNumber('checkId');
        Route::post('checks/{checkId}/room-stays', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'addRoomStay'])
            ->middleware('erp.permission:hotel_bar_pos.checks.edit')
            ->whereNumber('checkId');
        Route::patch('checks/{checkId}/lines/{lineId}', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'updateLine'])
            ->middleware('erp.permission:hotel_bar_pos.checks.edit')
            ->whereNumber('checkId')
            ->whereNumber('lineId');
        Route::delete('checks/{checkId}/lines/{lineId}', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'removeLine'])
            ->middleware('erp.permission:hotel_bar_pos.checks.edit')
            ->whereNumber('checkId')
            ->whereNumber('lineId');
        Route::post('checks/{checkId}/clear', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'clear'])
            ->middleware('erp.permission:hotel_bar_pos.checks.edit')
            ->whereNumber('checkId');
        Route::post('checks/{checkId}/hold', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'hold'])
            ->middleware('erp.permission:hotel_bar_pos.checks.edit')
            ->whereNumber('checkId');
        Route::post('checks/{checkId}/resume', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'resume'])
            ->middleware('erp.permission:hotel_bar_pos.checks.edit')
            ->whereNumber('checkId');
        Route::post('checks/{checkId}/settle', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'settle'])
            ->middleware('erp.permission:hotel_bar_pos.checks.edit')
            ->whereNumber('checkId');
        Route::post('checks/{checkId}/save', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'save'])
            ->middleware('erp.permission:hotel_bar_pos.checks.edit')
            ->whereNumber('checkId');
        Route::post('checks/{checkId}/void', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityPosController::class, 'voidCheck'])
            ->middleware('erp.permission:hotel_bar_pos.checks.edit')
            ->whereNumber('checkId');
    });

    // ---- Hospitality Backoffice (settings + F&B recipes + rooms/outlets) ----
    Route::prefix('hospitality')->group(function () {
        Route::get('settings', [\App\Http\Controllers\Api\V1\Hospitality\HospitalitySettingsController::class, 'show'])
            ->middleware('erp.permission:hospitality.settings.view');
        Route::patch('settings', [\App\Http\Controllers\Api\V1\Hospitality\HospitalitySettingsController::class, 'update'])
            ->middleware('erp.permission:hospitality.settings.edit');
        Route::get('recipes', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityRecipeController::class, 'index'])
            ->middleware('erp.permission:hospitality.settings.view');
        Route::post('recipes', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityRecipeController::class, 'store'])
            ->middleware('erp.permission:hospitality.settings.edit');
        Route::put('recipes/{recipeId}', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityRecipeController::class, 'update'])
            ->middleware('erp.permission:hospitality.settings.edit')
            ->whereNumber('recipeId');
        Route::delete('recipes/{recipeId}', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityRecipeController::class, 'destroy'])
            ->middleware('erp.permission:hospitality.settings.edit')
            ->whereNumber('recipeId');

        Route::get('outlets', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityOutletController::class, 'index'])
            ->middleware('erp.permission:hospitality.outlets.view');
        Route::post('outlets', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityOutletController::class, 'store'])
            ->middleware('erp.permission:hospitality.outlets.create');
        Route::patch('outlets/{id}', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityOutletController::class, 'update'])
            ->middleware('erp.permission:hospitality.outlets.edit')
            ->whereNumber('id');

        Route::get('floor-tables', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityFloorTableController::class, 'index'])
            ->middleware('erp.permission:hospitality.outlets.view');
        Route::post('floor-tables', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityFloorTableController::class, 'store'])
            ->middleware('erp.permission:hospitality.outlets.create');
        Route::patch('floor-tables/{id}', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityFloorTableController::class, 'update'])
            ->middleware('erp.permission:hospitality.outlets.edit')
            ->whereNumber('id');

        Route::apiResource('room-types', \App\Http\Controllers\Api\V1\Hospitality\HospitalityRoomTypeController::class)
            ->middlewareFor(['index', 'show'], ['erp.permission:hospitality.rooms.view'])
            ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:hospitality.rooms.create|hospitality.rooms.edit']);
        Route::post('rooms/bulk', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityRoomController::class, 'bulkStore'])
            ->middleware('erp.permission:hospitality.rooms.create|hospitality.rooms.edit');
        Route::apiResource('rooms', \App\Http\Controllers\Api\V1\Hospitality\HospitalityRoomController::class)
            ->middlewareFor(['index', 'show'], ['erp.permission:hospitality.rooms.view'])
            ->middlewareFor(['store', 'update', 'destroy'], ['erp.permission:hospitality.rooms.create|hospitality.rooms.edit']);

        Route::get('rate-plans', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityRatePlanController::class, 'index'])
            ->middleware('erp.permission:hospitality.rooms.view');
        Route::post('rate-plans', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityRatePlanController::class, 'store'])
            ->middleware('erp.permission:hospitality.rooms.create|hospitality.rooms.edit');
        Route::put('rate-plans/{id}', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityRatePlanController::class, 'update'])
            ->middleware('erp.permission:hospitality.rooms.edit')
            ->whereNumber('id');
        Route::delete('rate-plans/{id}', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityRatePlanController::class, 'destroy'])
            ->middleware('erp.permission:hospitality.rooms.edit')
            ->whereNumber('id');

        Route::get('dashboard', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityDashboardController::class, 'summary'])
            ->middleware('erp.permission:hospitality.dashboard.view');

        Route::get('checks', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityCheckController::class, 'index'])
            ->middleware('erp.permission:hospitality.dashboard.view|hospitality.reports.view|hospitality.orders.view|hotel_bar_pos.checks.view');
        Route::get('checks/{id}', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityCheckController::class, 'show'])
            ->middleware('erp.permission:hospitality.dashboard.view|hospitality.reports.view|hospitality.orders.view|hotel_bar_pos.checks.view')
            ->whereNumber('id');

        Route::get('reservations', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReservationController::class, 'index'])
            ->middleware('erp.permission:hospitality.reservations.view');
        Route::post('reservations', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReservationController::class, 'store'])
            ->middleware('erp.permission:hospitality.reservations.create');
        Route::get('reservations/{id}', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReservationController::class, 'show'])
            ->middleware('erp.permission:hospitality.reservations.view')
            ->whereNumber('id');
        Route::put('reservations/{id}', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReservationController::class, 'update'])
            ->middleware('erp.permission:hospitality.reservations.edit')
            ->whereNumber('id');
        Route::post('reservations/{id}/status', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReservationController::class, 'setStatus'])
            ->middleware('erp.permission:hospitality.reservations.edit')
            ->whereNumber('id');

        Route::get('folios', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityFolioController::class, 'index'])
            ->middleware('erp.permission:hospitality.folios.view');
        Route::get('folios/open', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityFolioController::class, 'openList'])
            ->middleware('erp.permission:hospitality.folios.view|hotel_bar_pos.room_charge.create');
        Route::post('folios', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityFolioController::class, 'store'])
            ->middleware('erp.permission:hospitality.folios.create');
        Route::get('folios/{id}', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityFolioController::class, 'show'])
            ->middleware('erp.permission:hospitality.folios.view')
            ->whereNumber('id');
        Route::post('folios/{id}/charges', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityFolioController::class, 'addCharge'])
            ->middleware('erp.permission:hospitality.folios.edit')
            ->whereNumber('id');
        Route::post('folios/{id}/payments', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityFolioController::class, 'addPayment'])
            ->middleware('erp.permission:hospitality.folios.edit')
            ->whereNumber('id');

        Route::get('front-desk/arrivals', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityFrontDeskController::class, 'arrivals'])
            ->middleware('erp.permission:hospitality.frontdesk.view');
        Route::get('front-desk/departures', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityFrontDeskController::class, 'departures'])
            ->middleware('erp.permission:hospitality.frontdesk.view');
        Route::get('front-desk/in-house', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityFrontDeskController::class, 'inHouse'])
            ->middleware('erp.permission:hospitality.frontdesk.view');
        Route::post('front-desk/check-in', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityFrontDeskController::class, 'checkIn'])
            ->middleware('erp.permission:hospitality.frontdesk.edit');
        Route::post('front-desk/folios/{folioId}/check-out', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityFrontDeskController::class, 'checkOut'])
            ->middleware('erp.permission:hospitality.frontdesk.edit')
            ->whereNumber('folioId');
        Route::post('front-desk/folios/{folioId}/assign-room', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityFrontDeskController::class, 'assignRoom'])
            ->middleware('erp.permission:hospitality.frontdesk.edit')
            ->whereNumber('folioId');
        Route::post('front-desk/rooms/{roomId}/check-out', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityFrontDeskController::class, 'checkOutRoom'])
            ->middleware('erp.permission:hospitality.frontdesk.edit')
            ->whereNumber('roomId');
        Route::post('front-desk/rooms/{roomId}/assign-room', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityFrontDeskController::class, 'reassignOccupiedRoom'])
            ->middleware('erp.permission:hospitality.frontdesk.edit')
            ->whereNumber('roomId');

        Route::get('housekeeping', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityHousekeepingController::class, 'board'])
            ->middleware('erp.permission:hospitality.housekeeping.view');
        Route::patch('housekeeping/rooms/{roomId}', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityHousekeepingController::class, 'setStatus'])
            ->middleware('erp.permission:hospitality.housekeeping.edit')
            ->whereNumber('roomId');

        Route::get('night-audit/preview', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityNightAuditController::class, 'preview'])
            ->middleware('erp.permission:hospitality.night_audit.view');
        Route::get('night-audit/history', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityNightAuditController::class, 'history'])
            ->middleware('erp.permission:hospitality.night_audit.view');
        Route::post('night-audit/run', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityNightAuditController::class, 'run'])
            ->middleware('erp.permission:hospitality.night_audit.create');

        Route::get('reports/{slug}', [\App\Http\Controllers\Api\V1\Hospitality\HospitalityReportController::class, 'show'])
            ->middleware('erp.permission:hospitality.reports.view');
    });

    // ---- AI assistant ----
    Route::middleware('erp.permission:ai.assist')->prefix('ai')->group(function () {
        Route::get('status', [\App\Http\Controllers\Api\V1\AiAssistantController::class, 'status']);
        Route::get('schemas', [\App\Http\Controllers\Api\V1\AiAssistantController::class, 'schemas']);
        Route::post('chat', [\App\Http\Controllers\Api\V1\AiAssistantController::class, 'chat']);
        Route::post('teach', [\App\Http\Controllers\Api\V1\AiAssistantController::class, 'teach']);
        Route::post('explore', [\App\Http\Controllers\Api\V1\AiAssistantController::class, 'explore']);
        Route::post('knowledge/{id}/confirm', [\App\Http\Controllers\Api\V1\AiAssistantController::class, 'confirmKnowledge']);
        Route::delete('knowledge/{id}', [\App\Http\Controllers\Api\V1\AiAssistantController::class, 'discardKnowledge']);

        Route::prefix('insights')->group(function () {
            Route::get('dashboard', [\App\Http\Controllers\Api\V1\AiInsightsController::class, 'dashboard']);
            Route::get('catalog', [\App\Http\Controllers\Api\V1\AiInsightsController::class, 'catalog']);
            Route::post('analyze-report', [\App\Http\Controllers\Api\V1\AiInsightsController::class, 'analyzeReport']);
            Route::post('ask', [\App\Http\Controllers\Api\V1\AiInsightsController::class, 'ask']);
            Route::post('stock-pulse', [\App\Http\Controllers\Api\V1\AiInsightsController::class, 'stockPulse']);
            Route::post('sales-brief', [\App\Http\Controllers\Api\V1\AiInsightsController::class, 'salesBrief']);
            Route::post('run', [\App\Http\Controllers\Api\V1\AiInsightsController::class, 'run']);
            Route::post('explain-screen', [\App\Http\Controllers\Api\V1\AiInsightsController::class, 'explainScreen']);
            Route::post('deliver', [\App\Http\Controllers\Api\V1\AiInsightsController::class, 'deliver']);
        });
    });
});
