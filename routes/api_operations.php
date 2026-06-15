<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Operations\CartOperationsController;
use App\Http\Controllers\Api\V1\Operations\CheckoutController;
use App\Http\Controllers\Api\V1\Operations\OrderWorkflowController;
use App\Http\Controllers\Api\V1\Operations\StockOperationsController;
use App\Http\Controllers\Api\V1\Operations\StockTransferController;
use App\Http\Controllers\Api\V1\Operations\LpoReceiveController;
use App\Http\Controllers\Api\V1\Operations\PaymentOperationsController;
use App\Http\Controllers\Api\V1\Operations\TillOperationsController;
use App\Http\Controllers\Api\V1\Operations\ReportController;
use App\Http\Controllers\Api\V1\Operations\ExternalAccountingController;
use App\Http\Controllers\Api\V1\Operations\FiscalPeriodController;
use App\Http\Controllers\Api\V1\Operations\JournalOperationsController;
use App\Http\Controllers\Api\V1\Operations\AccountingSettingsController;
use App\Http\Controllers\Api\V1\Operations\YearEndCloseController;
use App\Http\Controllers\Api\V1\Operations\AccountingReportController;
use App\Http\Controllers\Api\V1\Operations\AttendanceClockController;
use App\Http\Controllers\Api\V1\Operations\PayrollOperationsController;
use App\Http\Controllers\Api\V1\Operations\ReturnOperationsController;
use App\Http\Controllers\Api\V1\Operations\MpesaPaymentController;
use App\Http\Controllers\Api\V1\Operations\KraProductRegistrationController;

Route::middleware('auth:sanctum')->group(function () {

    // ---- Sales ----
    Route::middleware('erp.permission:sales.create')->prefix('sales')->group(function () {
        Route::post('carts', [CartOperationsController::class, 'store']);
        Route::get('carts/{cartId}', [CartOperationsController::class, 'show']);
        Route::patch('carts/{cartId}', [CartOperationsController::class, 'update']);
        Route::post('carts/{cartId}/lines', [CartOperationsController::class, 'addLine']);
        Route::patch('carts/{cartId}/lines/{lineRef}', [CartOperationsController::class, 'updateLine']);
        Route::delete('carts/{cartId}/lines/{lineRef}', [CartOperationsController::class, 'deleteLine']);
        Route::delete('carts/{cartId}/lines', [CartOperationsController::class, 'clear']);
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
        Route::post('orders/{saleId}/restore-to-cart', [CartOperationsController::class, 'restoreHeldOrder']);
        Route::post('orders/{saleId}/cancel-held', [CartOperationsController::class, 'cancelHeldOrder']);
    });

    Route::middleware('erp.permission:sales.manage')->prefix('sales')->group(function () {
        Route::post('orders/{saleId}/transition', [OrderWorkflowController::class, 'transition']);
    });

    Route::middleware(['erp.module:payments', 'erp.permission:payments.manage'])->group(function () {
        Route::post('sales/{saleId}/payments', [PaymentOperationsController::class, 'paySale']);
    });

    // ---- POS till ----
    Route::middleware(['erp.module:sales.pos', 'erp.permission:pos.till'])->prefix('pos')->group(function () {
        Route::post('sessions/open', [TillOperationsController::class, 'openSession']);
        Route::post('sessions/{sessionId}/add-float', [TillOperationsController::class, 'addFloat']);
        Route::post('sessions/{sessionId}/cash-movement', [TillOperationsController::class, 'recordCashMovement']);
        Route::post('sessions/{sessionId}/suspend', [TillOperationsController::class, 'suspendSession']);
        Route::post('sessions/{sessionId}/resume', [TillOperationsController::class, 'resumeSession']);
        Route::post('sessions/{sessionId}/handover', [TillOperationsController::class, 'handoverSession']);
        Route::post('sessions/{sessionId}/close', [TillOperationsController::class, 'closeSession']);
        Route::get('sessions/{sessionId}/x-report', [TillOperationsController::class, 'xReport']);
        Route::get('sessions/{sessionId}/z-report', [TillOperationsController::class, 'zReport']);
    });

    // ---- Inventory ----
    Route::middleware(['erp.module:inventory', 'erp.permission:inventory.view'])->prefix('inventory')->group(function () {
        Route::get('availability', [StockOperationsController::class, 'availability']);
    });

    Route::middleware(['erp.module:inventory', 'erp.permission:inventory.manage'])->prefix('inventory')->group(function () {
        Route::post('adjust', [StockOperationsController::class, 'adjust']);
        Route::post('transfer', [StockTransferController::class, 'store']);
        Route::post('receive', [LpoReceiveController::class, 'store']);
        Route::post('returns', [ReturnOperationsController::class, 'store']);
        Route::post('stock-take/{sessionId}/complete', [\App\Http\Controllers\Api\V1\Operations\StockTakeOperationsController::class, 'complete']);
    });

    // ---- Accounting ----
    Route::middleware(['erp.module:accounting', 'erp.permission:accounting.view'])->prefix('accounting')->group(function () {
        Route::get('settings', [AccountingSettingsController::class, 'show']);
    });

    Route::middleware(['erp.module:accounting', 'erp.permission:accounting.manage'])->prefix('accounting')->group(function () {
        Route::patch('settings', [AccountingSettingsController::class, 'update']);
        Route::post('seed-chart-of-accounts', [AccountingSettingsController::class, 'seedChart']);
        Route::post('journal-entries', [JournalOperationsController::class, 'store']);
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
        Route::post('{provider}/connect-stub', [ExternalAccountingController::class, 'connectStub'])
            ->where('provider', 'xero|sage');
        Route::post('{provider}/disconnect', [ExternalAccountingController::class, 'disconnectProvider'])
            ->where('provider', 'xero|sage');
        Route::get('account-mappings', [ExternalAccountingController::class, 'listMappings']);
        Route::put('account-mappings', [ExternalAccountingController::class, 'syncMappings']);
        Route::get('export-queue', [ExternalAccountingController::class, 'exportQueue']);
        Route::post('export-queue/process', [ExternalAccountingController::class, 'processExportQueue']);
    });

    // ---- HR / Attendance (clock device) ----
    Route::middleware(['erp.module:hr_payroll', 'erp.permission:hr.manage'])->prefix('attendance')->group(function () {
        Route::post('clock-in', [AttendanceClockController::class, 'clockIn']);
        Route::post('clock-out', [AttendanceClockController::class, 'clockOut']);
        Route::get('clock-sessions', [AttendanceClockController::class, 'sessions']);
    });

    // ---- HR / Payroll ----
    Route::middleware(['erp.module:hr_payroll', 'erp.permission:hr.manage'])->prefix('payroll')->group(function () {
        Route::get('kenya-statutory', [PayrollOperationsController::class, 'kenyaStatutory']);
        Route::get('run-schedule', [PayrollOperationsController::class, 'runSchedule']);
        Route::get('calculate', [PayrollOperationsController::class, 'calculate']);
        Route::post('runs/{runId}/process', [PayrollOperationsController::class, 'processRun']);
        Route::post('runs/{runId}/process-auto', [PayrollOperationsController::class, 'processAuto']);
    });

    // ---- KRA device ----
    Route::middleware('erp.permission:products.manage')->prefix('kra')->group(function () {
        Route::post('register-products', [KraProductRegistrationController::class, 'register']);
    });

    // ---- Reports ----
    Route::middleware(['erp.module:reports', 'erp.permission:reports.view'])->prefix('reports')->group(function () {
        Route::get('/', [ReportController::class, 'catalog']);
        Route::get('dashboard', [ReportController::class, 'dashboard']);
        Route::get('sales-by-product', [ReportController::class, 'salesByProduct']);
        Route::get('sales-by-user', [ReportController::class, 'salesByUser']);
        Route::get('sales-by-customer', [ReportController::class, 'salesByCustomer']);
        Route::get('sales-by-channel', [ReportController::class, 'salesByChannel']);
        Route::get('daily-sales', [ReportController::class, 'dailySales']);
        Route::get('mobile-route-sales', [ReportController::class, 'routeSales']);
        Route::get('sales-pipeline', [ReportController::class, 'salesPipeline']);
        Route::get('vat-collected', [ReportController::class, 'vatCollected']);
        Route::get('category-sales', [ReportController::class, 'categorySales']);
        Route::get('discount-summary', [ReportController::class, 'discountSummary']);
        Route::get('payment-collection', [ReportController::class, 'paymentCollection']);
        Route::get('credit-outstanding', [ReportController::class, 'creditOutstanding']);
        Route::get('stock-on-hand', [ReportController::class, 'stockOnHand']);
        Route::get('low-stock', [ReportController::class, 'lowStock']);
        Route::get('stock-movement', [ReportController::class, 'stockMovement']);
        Route::get('stock-chain', [ReportController::class, 'stockChain']);
        Route::get('stock-valuation', [ReportController::class, 'stockValuation']);
        Route::get('stock-reservations', [ReportController::class, 'stockReservations']);
        Route::get('stock-receipts', [ReportController::class, 'stockReceipts']);
        Route::get('stock-transfers', [ReportController::class, 'stockTransfers']);
        Route::get('open-lpo', [ReportController::class, 'openLpo']);
        Route::get('profit-loss', [ReportController::class, 'profitLoss']);
        Route::get('eod-cashier', [ReportController::class, 'eodCashier']);
        Route::get('eod-report', [ReportController::class, 'eodReport']);
        Route::get('ar-aging', [ReportController::class, 'arAging']);
        Route::get('top-debtors', [ReportController::class, 'topDebtors']);
        Route::get('invoice-payments', [ReportController::class, 'invoicePayments']);
        Route::get('purchases-by-supplier', [ReportController::class, 'purchasesBySupplier']);
        Route::get('expenses', [ReportController::class, 'expenses']);
        Route::get('damages', [ReportController::class, 'damages']);
        Route::get('supplier-returns', [ReportController::class, 'supplierReturns']);
        Route::get('kra-receipts', [ReportController::class, 'kraReceipts']);
        Route::get('journal-register', [ReportController::class, 'journalRegister']);
        Route::get('general-ledger', [AccountingReportController::class, 'generalLedger']);
        Route::get('trial-balance', [AccountingReportController::class, 'trialBalance']);
        Route::get('balance-sheet', [AccountingReportController::class, 'balanceSheet']);
        Route::get('profit-loss-gl', [AccountingReportController::class, 'profitLossGl']);
        Route::get('cash-flow', [AccountingReportController::class, 'cashFlow']);
        Route::get('accounts-receivable', [AccountingReportController::class, 'accountsReceivable']);
        Route::get('accounts-payable', [AccountingReportController::class, 'accountsPayable']);
        Route::get('subledger-reconciliation', [AccountingReportController::class, 'subledgerReconciliation']);
        Route::get('till-sessions', [ReportController::class, 'tillSessions']);
        Route::get('payroll-summary', [ReportController::class, 'payrollSummary']);
        Route::get('audit-trail', [ReportController::class, 'auditTrail']);
        Route::get('price-list', [ReportController::class, 'priceList']);
        Route::get('returns', [ReportController::class, 'returns']);
        Route::get('customers/{customerNum}/statement', [ReportController::class, 'customerStatement']);
    });
});
