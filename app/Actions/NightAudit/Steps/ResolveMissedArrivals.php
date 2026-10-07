<?php

namespace App\Actions\NightAudit\Steps;

use App\Actions\FrontDesk\MarkNoShow;
use App\Actions\NightAudit\RecordAuditException;
use App\Enums\AuditExceptionType;
use App\Enums\AuditStep;
use App\Enums\MissedArrivalPolicy;
use App\Enums\UnitStatus;
use App\Models\NightAudit;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class ResolveMissedArrivals implements AuditStepRunner
{
    public function __construct(
        private MarkNoShow $markNoShow,
        private RecordAuditException $recordAuditException,
    ) {}

    /**
     * Apply `missed_arrival_policy`. With Mark no-show, every Confirmed unit that arrives on or before the business
     * date and is not marked `late_arrival_expected` goes through `MarkNoShow` (the no-show fee and the release of its
     * rooms). With Keep as late arrival the step does nothing. A unit `MarkNoShow` refuses becomes an exception row.
     */
    public function run(NightAudit $audit, CarbonImmutable $date): StepOutcome
    {
        if ($audit->property->settings->missed_arrival_policy !== MissedArrivalPolicy::MarkNoShow) {
            return new StepOutcome;
        }

        $processed = 0;
        $failed = 0;

        ReservationUnit::query()
            ->where('property_id', $audit->property_id)
            ->where('status', UnitStatus::Confirmed->value)
            ->whereDate('arrival_date', '<=', $date->toDateString())
            ->where('late_arrival_expected', false)
            ->chunkById(100, function (Collection $units) use ($audit, &$processed, &$failed): void {
                foreach ($units as $unit) {
                    try {
                        $result = ($this->markNoShow)(null, $unit);
                        $refusal = $result->refusal;
                    } catch (Throwable $exception) {
                        report($exception);
                        $refusal = $exception->getMessage();
                    }

                    if ($refusal === null) {
                        $processed++;

                        continue;
                    }

                    $failed++;
                    ($this->recordAuditException)($audit, AuditStep::ResolveMissedArrivals, AuditExceptionType::MissedArrival, $refusal, $unit->id);
                }
            });

        return new StepOutcome($processed, $failed);
    }
}
