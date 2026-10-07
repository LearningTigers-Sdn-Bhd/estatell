<?php

namespace App\Actions\NightAudit\Steps;

use App\Enums\AuditStatus;
use App\Enums\BusinessDateStatus;
use App\Models\BusinessDate;
use App\Models\NightAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

class CloseAndOpenNext implements AuditStepRunner
{
    /**
     * Close the Auditing business date, open the next calendar day as the new Open date and complete the audit, in
     * one transaction. A date that is not Auditing throws, because a closed date never reopens. Reports and
     * notifications are dispatched here when the statistics item lands. See docs/20-night-audit/03-audit-run.md.
     */
    public function run(NightAudit $audit, CarbonImmutable $date): StepOutcome
    {
        DB::transaction(function () use ($audit, $date): void {
            $closed = BusinessDate::query()
                ->whereKey($audit->business_date_id)
                ->where('status', BusinessDateStatus::Auditing->value)
                ->update([
                    'status' => BusinessDateStatus::Closed->value,
                    'closed_at' => now(),
                    'closed_by' => $audit->run_by,
                ]);

            if ($closed === 0) {
                throw new LogicException('The business date is not Auditing, so it cannot be closed.');
            }

            $next = $audit->property->businessDates()->create([
                'date' => $date->addDay()->toDateString(),
                'status' => BusinessDateStatus::Open,
                'opened_at' => now(),
            ]);

            $audit->forceFill(['status' => AuditStatus::Completed, 'completed_at' => now()])->save();

            activity()
                ->performedOn($next)
                ->causedBy($audit->runBy)
                ->event('business-date.rolled')
                ->withProperties([
                    'property_id' => $audit->property_id,
                    'closed' => $date->toDateString(),
                    'opened' => $next->date->toDateString(),
                ])
                ->log('Business date closed and the next one opened');
        });

        return new StepOutcome(1);
    }
}
