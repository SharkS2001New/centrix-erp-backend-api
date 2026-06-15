<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\Expense;
use App\Models\TillFloatSession;
use App\Services\Accounting\ExpenseJournalService;
use App\Services\Erp\ErpContext;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ExpenseController extends BaseResourceController
{
    public function __construct(protected ErpContext $erp) {}

    protected function modelClass(): string
    {
        return Expense::class;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'required|integer',
            'expense_group_id' => 'required|integer',
            'float_session_id' => 'nullable|integer',
            'description' => 'nullable|string|max:200',
            'expense_amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'balance_due' => 'nullable|string|max:45',
            'invoice_no' => 'nullable|string|max:45',
            'billable_status' => 'nullable|integer',
            'payment_method_id' => 'required|integer',
        ]);

        if (! empty($data['float_session_id'])) {
            $session = TillFloatSession::find($data['float_session_id']);
            if (! $session || strtolower((string) $session->status) !== 'open') {
                throw new InvalidArgumentException('Expenses can only be linked to an open till session.');
            }
            if ((int) $session->branch_id !== (int) $data['branch_id']) {
                throw new InvalidArgumentException('Expense branch must match the till session branch.');
            }
        }

        $data['recorded_by'] = $request->user()->id;
        $expense = Expense::create($data);

        $gate = $this->erp->gateForUser($request->user());
        app(ExpenseJournalService::class)->postIfEnabled($expense, $request->user(), $gate);

        return response()->json($expense, 201);
    }
}
