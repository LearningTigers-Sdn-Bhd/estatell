<?php

namespace App\Actions\NightAudit\Steps;

use App\Actions\FrontDesk\ExtendStay;
use App\Actions\NightAudit\RecordAuditException;
use App\Enums\AuditExceptionType;
use App\Enums\AuditStep;
use App\Enums\OverdueDeparturePolicy;
use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\NightAudit;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class ResolveOverdueDepartures implements AuditStepRunner
{
    public const MESSAGE_BLOCKED = 'overdue_departure_blocked';

    public const MESSAGE_DAY_USE = 'day_use_not_extended';

    public const MESSAGE_PAST_DEPARTURE = 'departure_date_passed';

    public function __construct(
        private ExtendStay $extendStay,
        private RecordAuditException $recordAuditException,
    ) {}

    /**
     * Apply `overdue_departure_policy` to every CheckedIn unit that departs on or before the business date. With
     * Extend one night, a unit that departs on the business date goes through `ExtendStay` to the next day, so the
     * night of the business date posts in the next step. A unit that departs earlier is never extended, because the
     * nights between could not be posted on a closed date. With Block, no unit is extended. Each unit that is not
     * extended becomes an exception row, and so does one `ExtendStay` refuses. A day use is never extended, whatever
     * the policy: it becomes an exception row with `day_use_not_extended` and the guest is checked out by hand.
     */
    public function run(NightAudit $audit, CarbonImmutable $date): StepOutcome
    {
        $extend = $audit->property->settings->overdue_departure_policy === OverdueDeparturePolicy::ExtendOneNight;
        $processed = 0;
        $failed = 0;

        ReservationUnit::query()
            ->where('property_id', $audit->property_id)
            ->where('status', UnitStatus::CheckedIn->value)
            ->whereDate('departure_date', '<=', $date->toDateString())
            ->chunkById(100, function (Collection $units) use ($audit, $date, $extend, &$processed, &$failed): void {
                foreach ($units as $unit) {
                    $refusal = $this->resolve($unit, $date, $extend);

                    if ($refusal === null) {
                        $processed++;

                        continue;
                    }

                    $failed++;
                    ($this->recordAuditException)($audit, AuditStep::ResolveOverdueDepartures, AuditExceptionType::OverdueDeparture, $refusal, $unit->id);
                }
            });

        return new StepOutcome($processed, $failed);
    }

    /**
     * The refusal message, or null when the unit was extended.
     */
    private function resolve(ReservationUnit $unit, CarbonImmutable $date, bool $extend): ?string
    {
        if ($unit->stay_type === StayType::DayUse) {
            return self::MESSAGE_DAY_USE;
        }

        if (! $extend) {
            return self::MESSAGE_BLOCKED;
        }

        if ($unit->departure_date->toDateString() < $date->toDateString()) {
            return self::MESSAGE_PAST_DEPARTURE;
        }

        try {
            return ($this->extendStay)(null, $unit, $date->addDay())->refusal;
        } catch (Throwable $exception) {
            report($exception);

            return $exception->getMessage();
        }
    }
}
