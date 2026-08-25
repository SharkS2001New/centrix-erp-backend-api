<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Investor;
use App\Models\InvestorContribution;
use App\Models\InvestorSpendLink;
use App\Services\Auth\UserAccessService;
use App\Services\Erp\ErpContext;
use App\Services\Investors\InvestorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvestorController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected InvestorService $investors,
        protected UserAccessService $access,
    ) {}

    protected function orgId(Request $request): int
    {
        $orgId = $this->access->organizationId($request->user(), $request);
        abort_unless($orgId, 403);

        return (int) $orgId;
    }

    protected function findInvestor(Request $request, int $id): Investor
    {
        return Investor::query()
            ->where('organization_id', $this->orgId($request))
            ->where('id', $id)
            ->firstOrFail();
    }

    public function index(Request $request)
    {
        $orgId = $this->orgId($request);
        $query = Investor::query()
            ->where('organization_id', $orgId)
            ->withCount(['contributions', 'batches', 'spendLinks'])
            ->orderBy('investor_name');

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($inner) use ($q) {
                $inner->where('investor_name', 'like', "%{$q}%")
                    ->orWhere('investor_code', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = min(max((int) $request->input('per_page', 50), 1), 200);
        $page = $query->paginate($perPage);
        $page->getCollection()->transform(function (Investor $investor) {
            $summary = $this->investors->accountSummary($investor);

            return array_merge($investor->toArray(), ['summary' => $summary]);
        });

        return response()->json($page);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'investor_code' => ['nullable', 'string', 'max:40'],
            'investor_name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $investor = $this->investors->createInvestor(
            $this->orgId($request),
            $data,
            $request->user()?->id,
        );

        return response()->json($investor, 201);
    }

    public function show(Request $request, int $id)
    {
        $investor = $this->findInvestor($request, $id);
        $investor->load([
            'contributions' => fn ($q) => $q->orderByDesc('contribution_date')->orderByDesc('id'),
            'contributions.supplier:id,supplier_name,supplier_code',
            'contributions.batches',
            'batches',
            'spendLinks' => fn ($q) => $q->orderByDesc('spend_date')->orderByDesc('id'),
        ]);

        return response()->json([
            'data' => $investor,
            'summary' => $this->investors->accountSummary($investor),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $investor = $this->findInvestor($request, $id);
        $data = $request->validate([
            'investor_code' => ['sometimes', 'string', 'max:40'],
            'investor_name' => ['sometimes', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->investors->updateInvestor($investor, $data));
    }

    public function destroy(Request $request, int $id)
    {
        $investor = $this->findInvestor($request, $id);
        $investor->delete();

        return response()->json(['message' => 'Investor deleted.']);
    }

    public function storeContribution(Request $request, int $id)
    {
        $investor = $this->findInvestor($request, $id);
        $data = $request->validate([
            'contribution_type' => ['required', Rule::in(['cash', 'stock'])],
            'contribution_date' => ['required', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method_id' => ['nullable', 'integer'],
            'payment_code' => ['nullable', 'string', 'max:120'],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'supplier_id' => ['nullable', 'integer'],
            'lpo_no' => ['nullable', 'integer'],
            'supplier_payment_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $contribution = $this->investors->recordContribution($investor, $data, $request->user()?->id);

        return response()->json($contribution->load(['supplier', 'batches']), 201);
    }

    public function linkContribution(Request $request, int $id, int $contributionId)
    {
        $investor = $this->findInvestor($request, $id);
        $contribution = InvestorContribution::query()
            ->where('organization_id', $investor->organization_id)
            ->where('investor_id', $investor->id)
            ->where('id', $contributionId)
            ->firstOrFail();

        $data = $request->validate([
            'payment_code' => ['nullable', 'string', 'max:120'],
            'supplier_payment_id' => ['nullable', 'integer'],
            'lpo_no' => ['nullable', 'integer'],
        ]);

        return response()->json($this->investors->linkContributionPayment($contribution, $data));
    }

    public function allocateProducts(Request $request, int $id, int $contributionId)
    {
        $investor = $this->findInvestor($request, $id);
        $contribution = InvestorContribution::query()
            ->where('organization_id', $investor->organization_id)
            ->where('investor_id', $investor->id)
            ->where('id', $contributionId)
            ->firstOrFail();

        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_code' => ['required', 'string'],
            'lines.*.product_name' => ['nullable', 'string'],
            'lines.*.packaging' => ['nullable', 'string'],
            'lines.*.qty_purchased' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.lpo_no' => ['nullable', 'integer'],
            'lines.*.received_at' => ['nullable', 'date'],
            'from_lpo' => ['sometimes', 'boolean'],
            'lpo_no' => ['nullable', 'integer'],
        ]);

        if (! empty($data['from_lpo']) && ! empty($data['lpo_no'])) {
            $batches = $this->investors->allocateFromLpo($contribution, (int) $data['lpo_no']);
        } else {
            $batches = $this->investors->allocateProducts($contribution, $data['lines']);
        }

        return response()->json(['data' => $batches->values()], 201);
    }

    public function storeSpend(Request $request, int $id)
    {
        $investor = $this->findInvestor($request, $id);
        $data = $request->validate([
            'spend_type' => ['required', Rule::in([
                InvestorSpendLink::TYPE_SUPPLIER_PAYMENT,
                InvestorSpendLink::TYPE_EXPENSE,
                InvestorSpendLink::TYPE_OTHER,
            ])],
            'reference_id' => ['nullable', 'integer'],
            'reference_label' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'spend_date' => ['required', 'date'],
            'contribution_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ]);

        $link = $this->investors->recordSpend($investor, $data, $request->user()?->id);

        return response()->json($link, 201);
    }

    public function salesReport(Request $request, int $id)
    {
        $investor = $this->findInvestor($request, $id);

        return response()->json($this->investors->salesReport(
            $investor,
            $request->input('from_date'),
            $request->input('to_date'),
        ));
    }

    public function stockReport(Request $request, int $id)
    {
        $investor = $this->findInvestor($request, $id);

        return response()->json($this->investors->stockReport($investor));
    }

    public function moneyFlowReport(Request $request, int $id)
    {
        $investor = $this->findInvestor($request, $id);

        return response()->json($this->investors->moneyFlowReport(
            $investor,
            $request->input('from_date'),
            $request->input('to_date'),
        ));
    }
}
