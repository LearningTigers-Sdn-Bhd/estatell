<?php

namespace App\Services\FrontDesk;

use App\Data\Reservations\UnitPendingLineData;
use App\Enums\ChargingUnit;
use App\Enums\StayType;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\UnitAddOn;
use App\Models\UnitNight;
use App\Services\Folio\NightChargeQuote;
use App\Services\Folio\ScheduledFolioRows;
use App\Services\Rates\RateQuoteRequest;
use App\Services\Rates\RateQuoteService;
use App\Services\Reservations\UnitNationality;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * The pending night of a unit: the night of the business date while the night audit is pending, and the add-ons dated
 * that day. `applies()` is the one rule `PostPendingNight` and the check-out preview both ask. `lines()` reads what
 * the check-out will post, through `ScheduledFolioRows` (the same quote `PostRoomCharges` uses), and keeps the lines
 * that land on the unit's own folios, so the preview balance matches the folio after the posting. It reads only.
 * See docs/18-front-desk/01-check-in-and-check-out.md#night-audit-pending.
 */
class PendingNightQuote
{
    public function __construct(
        private StayDay $stayDay,
        private ScheduledFolioRows $scheduledFolioRows,
        private CheckOutSettlement $checkOutSettlement,
        private NightChargeQuote $nightChargeQuote,
        private RateQuoteService $rateQuoteService,
        private UnitNationality $unitNationality,
    ) {}

    /**
     * The audit is pending, the unit is CheckedIn and it has a night on the business date.
     */
    public function applies(ReservationUnit $unit, CarbonImmutable $businessDate): bool
    {
        return $unit->status === UnitStatus::CheckedIn
            && $this->stayDay->isAuditPending($unit->property, $businessDate)
            && $unit->nights()->whereDate('date', $businessDate->toDateString())->exists();
    }

    /**
     * @return list<UnitPendingLineData>
     */
    public function lines(ReservationUnit $unit, CarbonImmutable $businessDate): array
    {
        if (! $this->applies($unit, $businessDate)) {
            return [];
        }

        $date = $businessDate->toDateString();
        $property = $unit->property()->with('settings')->firstOrFail();
        $reservation = Reservation::query()->with('booker')->findOrFail($unit->reservation_id);
        $own = $this->checkOutSettlement->folios($unit)->modelKeys();
        $addOnIds = UnitAddOn::query()->where('reservation_unit_id', $unit->id)->whereNull('folio_line_id')->whereDate('service_date', $date)->pluck('id')->map(fn ($id): string => 'addon:'.$id)->all();
        $lines = [];

        foreach ($this->scheduledFolioRows->forReservation($property, $reservation, []) as $block) {
            if ($block['unit'] !== $unit->id || $block['date'] !== $date || ($block['target'] !== null && ! in_array($block['target'], $own, true))) {
                continue;
            }

            foreach ($block['rows'] as $row) {
                $root = implode(':', array_slice(explode(':', $row->key), 0, 2));

                if (str_starts_with($root, 'addon:') && ! in_array($root, $addOnIds, true)) {
                    continue;
                }

                $lines[] = new UnitPendingLineData($row->description, $row->amount, $row->level);
            }
        }

        return $lines;
    }

    /**
     * @param  list<UnitPendingLineData>  $lines
     */
    public function total(array $lines): BigDecimal
    {
        return array_reduce($lines, fn (BigDecimal $sum, UnitPendingLineData $line): BigDecimal => $sum->plus($line->amount), BigDecimal::zero());
    }

    /**
     * Whether the unit is an Overdue guest: CheckedIn, due to leave on the business date, with the audit pending.
     */
    public function isOverdue(ReservationUnit $unit, CarbonImmutable $businessDate): bool
    {
        return $unit->status === UnitStatus::CheckedIn
            && $unit->stay_type !== StayType::DayUse
            && $unit->departure_date->toDateString() === $businessDate->toDateString()
            && $this->stayDay->isAuditPending($unit->property, $businessDate);
    }

    /**
     * The lines "They stayed last night" would post for the night of the business date: the room, its inclusions,
     * discounts and tax from `NightChargeQuote` on an unsaved night priced by `RateQuoteService`, and the nightly
     * add-ons `AddNightlyAddOnRows` would copy. Empty when the unit is not an Overdue guest or the night cannot be quoted.
     *
     * @return list<UnitPendingLineData>
     */
    public function overdueLines(ReservationUnit $unit, CarbonImmutable $businessDate): array
    {
        if (! $this->isOverdue($unit, $businessDate)) {
            return [];
        }

        $property = $unit->property()->with('settings')->firstOrFail();
        $roomCode = TransactionCode::query()->where('property_id', $property->id)->where('system_key', SystemTransactionCode::RoomRevenue->value)->first();
        $ratePlan = $unit->ratePlan()->with('inclusions.chargeItem.transactionCode')->firstOrFail();
        $quote = $this->rateQuoteService->quote(new RateQuoteRequest(
            $ratePlan,
            $unit->roomType()->firstOrFail(),
            $businessDate,
            $businessDate->addDay(),
            $unit->adults,
            array_values($unit->childAges()->pluck('age')->map(fn (mixed $age): int => (int) $age)->all()),
            1,
        ));

        if ($roomCode === null || $quote->isRefused()) {
            return [];
        }

        $price = $quote->nights[0];
        $night = new UnitNight([
            'date' => $businessDate->toDateString(),
            'rate_plan_id' => $ratePlan->id,
            'base_amount' => (string) $price->base->getAmount(),
            'extra_amount' => (string) $price->extra->getAmount(),
            'supplement_amount' => (string) $price->supplement->getAmount(),
            'discount_amount' => (string) $price->discountAmount->getAmount(),
        ]);
        $night->setRelation('ratePlan', $ratePlan);
        $unit->loadMissing('discount');
        $nationality = $this->unitNationality->for($unit);
        $nightQuote = $this->nightChargeQuote->quote($property, $this->nightChargeQuote->propertyTaxes($property), $unit, $night, false, $roomCode, $nationality);

        if ($nightQuote->isRefused()) {
            return [];
        }

        $date = $businessDate->toDateString();
        $currency = $property->settings->currency->getCurrencyCode();
        $rows = [];

        foreach ($nightQuote->charges as $charge) {
            array_push($rows, ...$this->scheduledFolioRows->chargeRows($charge, $date, false, null));
        }

        $nightly = [ChargingUnit::PerNight->value, ChargingUnit::PerGuestNight->value];
        $templates = $unit->addOns()->with('chargeItem.transactionCode', 'ageGroup')
            ->whereHas('chargeItem', fn ($item) => $item->whereIn('charging_unit', $nightly))
            ->orderBy('id')
            ->get()
            ->keyBy(fn (UnitAddOn $addOn): string => $addOn->charge_item_id.':'.($addOn->property_age_group_id ?? 'adult'));

        foreach ($templates as $template) {
            $exists = $unit->addOns()->where('charge_item_id', $template->charge_item_id)->where('property_age_group_id', $template->property_age_group_id)->whereDate('service_date', $date)->exists();

            if (! $exists) {
                array_push($rows, ...$this->scheduledFolioRows->addOnRows($property, $template, $template->chargeItem->transactionCode, $date, $currency, $nationality, null));
            }
        }

        foreach ($unit->addOns()->with('chargeItem.transactionCode', 'ageGroup')->whereNull('folio_line_id')->whereDate('service_date', $date)->orderBy('id')->get() as $dated) {
            array_push($rows, ...$this->scheduledFolioRows->addOnRows($property, $dated, $dated->chargeItem->transactionCode, $date, $currency, $nationality, null));
        }

        return array_map(fn ($row): UnitPendingLineData => new UnitPendingLineData($row->description, $row->amount, $row->level), $rows);
    }
}
