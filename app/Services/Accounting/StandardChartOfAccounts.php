<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Organization;

class StandardChartOfAccounts
{
    /** @return list<array{account_code: string, account_name: string, account_type: string}> */
    public function template(): array
    {
        return config('erp.standard_chart_of_accounts', []);
    }

    /** @return list<ChartOfAccount> */
    public function seedForOrganization(Organization|int $organization): array
    {
        $orgId = $organization instanceof Organization ? (int) $organization->id : (int) $organization;
        $created = [];

        foreach ($this->template() as $row) {
            $account = ChartOfAccount::firstOrCreate(
                [
                    'organization_id' => $orgId,
                    'account_code' => $row['account_code'],
                ],
                [
                    'account_name' => $row['account_name'],
                    'account_type' => $row['account_type'],
                    'is_active' => true,
                ],
            );
            $created[] = $account;
        }

        return $created;
    }

    public function isSeeded(int $orgId): bool
    {
        $codes = collect($this->template())->pluck('account_code');

        return ChartOfAccount::query()
            ->where('organization_id', $orgId)
            ->whereIn('account_code', $codes)
            ->count() >= $codes->count();
    }
}
