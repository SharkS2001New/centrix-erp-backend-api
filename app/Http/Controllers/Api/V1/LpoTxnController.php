<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\LpoTxn;
use App\Services\LpoModuleService;
use Illuminate\Http\Request;

class LpoTxnController extends BaseResourceController
{
    public function __construct(
        protected LpoModuleService $lpoModule,
    ) {}

    protected function modelClass(): string
    {
        return LpoTxn::class;
    }

    protected function scopesByOrganization(): bool
    {
        return false;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $orgId = (int) ($user?->organization_id ?? 0);

        $query = LpoTxn::query()
            ->with(['lpo:id,lpo_no,lpo_seq,organization_id,created_at,sent_at,reference_number'])
            ->when($orgId > 0, fn ($builder) => $builder->whereHas(
                'lpo',
                fn ($lpo) => $lpo->where('organization_id', $orgId),
            ));

        foreach ((array) $request->input('filter', []) as $col => $val) {
            if (in_array($col, $this->fillableFields(), true)) {
                $query->where($col, $val);
            }
        }

        if ($q = $request->input('q')) {
            $query->where('product_code', 'like', "%{$q}%");
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $paginator = $query->orderByDesc('id')->paginate($perPage);
        $paginator->getCollection()->transform(function (LpoTxn $line) {
            $lpo = $line->lpo;
            $orderDate = $lpo?->created_at ?? $lpo?->sent_at;

            return array_merge($line->toArray(), [
                'lpo_seq' => $lpo?->lpo_seq,
                'po_number' => $lpo
                    ? $this->lpoModule->formatPoNumber((int) $lpo->lpo_seq, $orderDate)
                    : null,
                'lpo_order_date' => $orderDate,
            ]);
        });

        return response()->json($paginator);
    }
}
