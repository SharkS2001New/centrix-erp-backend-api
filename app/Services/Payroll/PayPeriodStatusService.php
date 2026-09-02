<?php

namespace App\Services\Payroll;

use App\Models\PayPeriod;
use App\Models\PayrollRun;

class PayPeriodStatusService
{
    /** @return 'open'|'closed' */
    public function desiredStatus(PayPeriod $period): string
    {
        $hasPaidRun = PayrollRun::query()
            ->where('pay_period_id', $period->id)
            ->where('status', 'paid')
            ->exists();

        return $hasPaidRun ? 'closed' : 'open';
    }

    public function sync(PayPeriod $period): PayPeriod
    {
        $desired = $this->desiredStatus($period);
        if ($period->status !== $desired) {
            $period->update(['status' => $desired]);
            $period->refresh();
        }

        return $period;
    }
}
