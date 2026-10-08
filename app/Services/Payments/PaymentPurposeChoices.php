<?php

namespace App\Services\Payments;

use App\Enums\FolioType;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStage;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Folio\FolioRouter;

/**
 * The payment purposes one folio (or a "not started" unit entry) offers, by its stage. The first is the default; none
 * means the folio has no Record payment button. A Unit folio or entry before arrival has none when its Room charges go
 * to the Master folio, because `RecordPayment` would refuse the Advance. It reads only and the browser only renders
 * the answer. See docs/14-payments/01-payments-and-refunds.md#purposes-by-stage.
 */
class PaymentPurposeChoices
{
    public function __construct(private FolioRouter $folioRouter) {}

    /**
     * Give a folio, or the unit id of a "not started" entry.
     *
     * @return list<PaymentPurpose>
     */
    public function for(Reservation $reservation, ?Folio $folio, ?int $entryUnitId): array
    {
        $type = $folio?->type ?? FolioType::Unit;

        if ($type === FolioType::ThirdParty) {
            return PaymentPurpose::forStage($type, PaymentStage::InHouse);
        }

        if ($type === FolioType::Master) {
            $statuses = $reservation->units()
                ->whereNotIn('status', [UnitStatus::Cancelled, UnitStatus::NoShow])
                ->pluck('status')
                ->all();

            return PaymentPurpose::forStage($type, PaymentStage::forMaster($statuses));
        }

        $unit = ReservationUnit::query()
            ->where('reservation_id', $reservation->id)
            ->find($folio?->reservation_unit_id ?? $entryUnitId);

        if ($unit === null) {
            return [];
        }

        $stage = PaymentStage::forUnit($unit->status);
        $purposes = PaymentPurpose::forStage($type, $stage);

        return $stage === PaymentStage::BeforeArrival && $this->folioRouter->roomChargesGoToMaster($unit) ? [] : $purposes;
    }
}
