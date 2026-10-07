<?php

namespace App\Actions\NightAudit;

use App\Enums\AuditStatus;
use App\Enums\AuditStep;
use App\Enums\AuditStepStatus;
use App\Enums\AuditTrigger;
use App\Models\NightAudit;
use App\Models\NightAuditStep;
use App\Models\Property;
use App\Models\User;
use App\Services\NightAudit\AuditReadiness;
use Carbon\CarbonImmutable;
use Throwable;

class RunNightAudit
{
    public function __construct(
        private ClaimBusinessDate $claimBusinessDate,
        private AuditReadiness $auditReadiness,
    ) {}

    /**
     * Close the current business date of a property: claim it, run the steps in order and open the next date.
     * A step that is already Completed is skipped, so running the audit again after a failure resumes it and posts
     * nothing twice. A step that throws is recorded as Failed with its error and stops the run, the date stays
     * Auditing and the audit is Failed until the next run. A unit that one step cannot resolve becomes an exception
     * row and does not stop the run. A run that would start the audit is refused while `AuditReadiness::gate()` says so
     * (`business_date_not_ended` before the system date has passed the business date, `blocked_by_readiness` while a
     * blocker is open), unless it is forced. A run that resumes a Failed audit is never blocked, because
     * its steps are already under way. A forced run needs a reason. The route checks `night-audit.run` and
     * `night-audit.force`, and the readiness panel's blockers come with that panel. A null actor is the scheduler.
     * See docs/20-night-audit/03-audit-run.md.
     */
    public function __invoke(Property $property, AuditTrigger $trigger = AuditTrigger::Manual, ?User $actor = null, bool $force = false, ?string $forceReason = null): NightAuditResult
    {
        $forceReason = filled($forceReason) ? trim((string) $forceReason) : null;

        if ($force && $forceReason === null) {
            return NightAuditResult::refused(NightAuditResult::REFUSAL_REASON_REQUIRED);
        }

        $gate = $force ? null : $this->auditReadiness->gate($property);

        if ($gate !== null) {
            return NightAuditResult::refused($gate);
        }

        $claim = ($this->claimBusinessDate)($property, $trigger, $actor, $force, $forceReason);

        if ($claim->isRefused()) {
            return $claim;
        }

        $audit = $claim->audit;
        $audit->load('businessDate');
        $date = CarbonImmutable::parse($audit->businessDate->date->toDateString());
        $completed = $audit->steps()->where('status', AuditStepStatus::Completed->value)->pluck('step')->all();

        foreach (AuditStep::runnable() as $step) {
            if (in_array($step, $completed, true)) {
                continue;
            }

            $row = $this->start($audit, $step);

            try {
                $outcome = app($step->runner())->run($audit, $date);
            } catch (Throwable $exception) {
                report($exception);

                return $this->fail($audit, $row, $exception);
            }

            $row->forceFill([
                'status' => AuditStepStatus::Completed,
                'processed_count' => $outcome->processed,
                'failed_count' => $outcome->failed,
                'finished_at' => now(),
            ])->save();
        }

        $audit->refresh();

        activity()
            ->performedOn($audit)
            ->causedBy($audit->runBy)
            ->event('night-audit.completed')
            ->withProperties([
                'property_id' => $audit->property_id,
                'business_date' => $date->toDateString(),
                'trigger' => $audit->trigger->value,
                'forced' => $audit->forced,
                'force_reason' => $audit->force_reason,
                'open_exceptions' => $audit->exceptions()->open()->count(),
            ])
            ->log('Night audit completed');

        return NightAuditResult::completed($audit);
    }

    /**
     * Start a step: a fresh Running row, and no exception rows from an earlier try of the same step.
     */
    private function start(NightAudit $audit, AuditStep $step): NightAuditStep
    {
        $audit->exceptions()->where('step', $step->value)->delete();

        $row = $audit->steps()->firstOrNew(['step' => $step]);
        $row->forceFill([
            'property_id' => $audit->property_id,
            'status' => AuditStepStatus::Running,
            'processed_count' => 0,
            'failed_count' => 0,
            'started_at' => now(),
            'finished_at' => null,
            'error_message' => null,
        ])->save();

        return $row;
    }

    private function fail(NightAudit $audit, NightAuditStep $row, Throwable $exception): NightAuditResult
    {
        $row->forceFill([
            'status' => AuditStepStatus::Failed,
            'finished_at' => now(),
            'error_message' => $exception::class.': '.$exception->getMessage(),
        ])->save();

        $audit->forceFill(['status' => AuditStatus::Failed])->save();

        activity()
            ->performedOn($audit)
            ->causedBy($audit->runBy)
            ->event('night-audit.failed')
            ->withProperties([
                'property_id' => $audit->property_id,
                'business_date' => $audit->businessDate->date->toDateString(),
                'step' => $row->step->value,
                'error' => $row->error_message,
            ])
            ->log('Night audit failed');

        return NightAuditResult::failed($audit);
    }
}
