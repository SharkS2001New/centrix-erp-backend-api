<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ScopesViaParentOrganization;
use App\Models\PayrollLine;
use Illuminate\Http\Request;

class PayrollLineController extends BaseResourceController
{
    use ScopesViaParentOrganization;

    protected function modelClass(): string
    {
        return PayrollLine::class;
    }

    protected function parentOrganizationScope(): array
    {
        return ['relation' => 'payrollRun.payPeriod'];
    }

    protected function baseQuery(Request $request)
    {
        return parent::baseQuery($request)->with('employee');
    }

    public function show(Request $request, string $id)
    {
        return response()->json($this->findScopedModel($request, $id)->load('employee'));
    }
}
