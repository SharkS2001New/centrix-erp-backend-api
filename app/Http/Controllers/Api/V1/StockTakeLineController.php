<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ScopesViaParentOrganization;
use App\Models\StockTakeLine;

class StockTakeLineController extends BaseResourceController
{
    use ScopesViaParentOrganization;

    protected function modelClass(): string
    {
        return StockTakeLine::class;
    }

    protected function parentOrganizationScope(): array
    {
        return ['relation' => 'session', 'via_branch' => true];
    }
}
