<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\LpoStatus;

class LpoStatusController extends BaseResourceController
{
    protected function modelClass(): string
    {
        return LpoStatus::class;
    }

    protected function scopesByOrganization(): bool
    {
        return false;
    }

    protected function sortableColumns(): array
    {
        return ['status_code', 'status_name'];
    }

    protected function defaultListOrderDirection(): string
    {
        return 'asc';
    }
}
