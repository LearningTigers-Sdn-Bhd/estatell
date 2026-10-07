<?php

namespace App\Actions\NightAudit\Steps;

use App\Enums\AuditStep;
use App\Enums\UnitStatus;
use App\Jobs\PostRoomChargesJob;
use App\Models\NightAudit;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

class PostRoomChargesStep implements AuditStepRunner
{
    /**
     * Post the night of the business date for every CheckedIn unit that has one: one `PostRoomChargesJob` per unit
     * in a `Bus::batch`. The batch runs on the sync connection, so the step ends when the batch ends and the next
     * step never starts early. Failures are isolated per unit: a unit that cannot post becomes an exception row.
     */
    public function run(NightAudit $audit, CarbonImmutable $date): StepOutcome
    {
        $unitIds = ReservationUnit::query()
            ->where('property_id', $audit->property_id)
            ->where('status', UnitStatus::CheckedIn->value)
            ->whereHas('nights', fn ($nights) => $nights->whereDate('date', $date->toDateString()))
            ->orderBy('id')
            ->pluck('id');

        if ($unitIds->isEmpty()) {
            return new StepOutcome;
        }

        Bus::batch($unitIds->map(fn (int $id): PostRoomChargesJob => new PostRoomChargesJob($audit->id, $id, $date->toDateString()))->all())
            ->name('night-audit:'.$audit->id.':room-charges')
            ->allowFailures()
            ->onConnection('sync')
            ->dispatch();

        $failed = $audit->exceptions()->where('step', AuditStep::PostRoomCharges->value)->count();

        return new StepOutcome($unitIds->count() - $failed, $failed);
    }
}
