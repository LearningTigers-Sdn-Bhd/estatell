<?php

namespace App\Actions\NightAudit;

use App\Actions\Folio\PostingResult;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\NightAudit\FirstBusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class PostMissedNights
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private FirstBusinessDate $firstBusinessDate,
        private PostRoomCharges $postRoomCharges,
        private PostScheduledAddOns $postScheduledAddOns,
    ) {}

    /**
     * Post every night of the unit that is before the business date, on or after the go-live date and has no
     * `night:{unit_night_id}` line, and every add-on dated in that range that has no line. Each night goes through
     * `PostRoomCharges` with that night as its date, the same path as a Retry: the line is posted on the business date
     * and `service_date` is the night. The keys do not change, so a rerun posts nothing twice and the audit never
     * charges the night again. The unit row is locked first. A refusal of any night rolls everything back and returns
     * that refusal. See docs/12-reservations/12-past-and-migrated-stays.md#postmissednights.
     */
    public function __invoke(?User $actor, ReservationUnit $unit): PostingResult
    {
        $property = $unit->property;
        $businessDate = $this->currentBusinessDate->date($property);
        $goLive = $this->firstBusinessDate->date($property);

        if ($businessDate === null || $goLive === null) {
            return PostingResult::refused(PostingResult::REFUSAL_NO_BUSINESS_DATE);
        }

        DB::beginTransaction();

        try {
            $locked = ReservationUnit::query()->lockForUpdate()->findOrFail($unit->id);
            $lines = [];

            foreach ($this->missedNights($locked, $goLive, $businessDate) as $date) {
                $result = ($this->postRoomCharges)($actor, $locked, CarbonImmutable::parse($date));

                if ($result->isRefused()) {
                    DB::rollBack();

                    return $result;
                }

                array_push($lines, ...$result->lines);
            }

            foreach ($this->missedAddOnIds($locked, $goLive, $businessDate) as $addOnId) {
                $result = $this->postScheduledAddOns->post($actor, $property, $addOnId, $businessDate);

                if ($result->isRefused()) {
                    DB::rollBack();

                    return $result;
                }

                array_push($lines, ...$result->lines);
            }

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return PostingResult::posted($lines);
    }

    /**
     * @return list<string>
     */
    private function missedNights(ReservationUnit $unit, CarbonImmutable $goLive, CarbonImmutable $businessDate): array
    {
        $nights = $unit->nights()
            ->whereDate('date', '>=', $goLive->toDateString())
            ->whereDate('date', '<', $businessDate->toDateString())
            ->orderBy('date')
            ->get(['id', 'date']);
        $posted = FolioLine::query()
            ->whereIn('idempotency_key', $nights->map(fn ($night): string => 'night:'.$night->id)->all())
            ->pluck('idempotency_key')
            ->all();

        return array_values($nights
            ->reject(fn ($night): bool => in_array('night:'.$night->id, $posted, true))
            ->map(fn ($night): string => $night->date->toDateString())
            ->all());
    }

    /**
     * @return list<int>
     */
    private function missedAddOnIds(ReservationUnit $unit, CarbonImmutable $goLive, CarbonImmutable $businessDate): array
    {
        return array_values(UnitAddOn::query()
            ->where('reservation_unit_id', $unit->id)
            ->whereNull('folio_line_id')
            ->whereDate('service_date', '>=', $goLive->toDateString())
            ->whereDate('service_date', '<', $businessDate->toDateString())
            ->orderBy('id')
            ->pluck('id')
            ->map(intval(...))
            ->all());
    }
}
