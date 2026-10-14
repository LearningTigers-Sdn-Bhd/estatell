<?php

namespace App\Actions\NightAudit;

use App\Enums\AuditStatus;
use App\Enums\AuditStep;
use App\Enums\AuditStepStatus;
use App\Enums\AuditTrigger;
use App\Enums\BusinessDateStatus;
use App\Models\BusinessDate;
use App\Models\NightAudit;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ClaimBusinessDate
{
    /**
     * Step 1 of the night audit: move the current business date from Open to Auditing and create its audit, or take
     * back a Failed audit of an Auditing date. The property row is locked and the status change is a conditional
     * update, so two runs never both claim. A date that is Auditing with a Running audit is refused as
     * `already_running`. The claim is recorded as a Completed step. A forced run keeps its reason on the audit.
     * See docs/20-night-audit/03-audit-run.md.
     */
    public function __invoke(Property $property, AuditTrigger $trigger, ?User $actor, bool $force, ?string $forceReason): NightAuditResult
    {
        return DB::transaction(function () use ($property, $trigger, $actor, $force, $forceReason): NightAuditResult {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $businessDate = $property->businessDates()->current()->with('nightAudit')->first();

            if ($businessDate === null) {
                return NightAuditResult::refused(NightAuditResult::REFUSAL_NO_BUSINESS_DATE);
            }

            $forced = ['forced' => $force, 'force_reason' => $force ? $forceReason : null];

            if ($businessDate->status === BusinessDateStatus::Open) {
                $claimed = BusinessDate::query()->whereKey($businessDate->id)->where('status', BusinessDateStatus::Open->value)->update(['status' => BusinessDateStatus::Auditing->value]);

                if ($claimed === 0) {
                    return NightAuditResult::refused(NightAuditResult::REFUSAL_ALREADY_RUNNING);
                }

                $audit = $property->nightAudits()->create([
                    'business_date_id' => $businessDate->id,
                    'trigger' => $trigger,
                    'status' => AuditStatus::Running,
                    'started_at' => now(),
                    'run_by' => $actor?->id,
                    ...$forced,
                ]);
            } else {
                $audit = $businessDate->nightAudit;
                $resumed = $audit === null ? 0 : NightAudit::query()->whereKey($audit->id)->where('status', AuditStatus::Failed->value)->update([
                    'status' => AuditStatus::Running->value,
                    'run_by' => $actor->id ?? $audit->run_by,
                    'forced' => $force || $audit->forced,
                    'force_reason' => $force ? $forceReason : $audit->force_reason,
                ]);

                if ($audit === null || $resumed === 0) {
                    return NightAuditResult::refused(NightAuditResult::REFUSAL_ALREADY_RUNNING);
                }

                $audit->refresh();
            }

            $step = $audit->steps()->firstOrNew(['step' => AuditStep::ClaimBusinessDate]);

            if (! $step->exists) {
                $step->property_id = $audit->property_id;
                $step->status = AuditStepStatus::Completed;
                $step->processed_count = 1;
                $step->started_at = now();
                $step->finished_at = now();
                $step->save();
            }

            return NightAuditResult::claimed($audit);
        });
    }
}
