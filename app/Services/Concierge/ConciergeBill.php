<?php

namespace App\Services\Concierge;

use App\Actions\Concierge\RequestConciergeCheckOut;
use App\Data\Concierge\ConciergeBillData;
use App\Data\Concierge\ConciergeBillLineData;
use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Services\Folio\FolioBalance;
use App\Services\FrontDesk\CheckOutSettlement;
use App\Services\Kiosk\KioskStays;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The guest's bill: the lines of the stay's own folios, what is still owed and what the property holds. It reads only.
 * A held deposit is a credit, so a stay with a deposit settles to zero only after the front desk gives it back.
 */
class ConciergeBill
{
    public function __construct(
        private CheckOutSettlement $settlement,
        private FolioBalance $folioBalance,
        private KioskStays $stays,
        private RequestConciergeCheckOut $checkOut,
    ) {}

    public function data(ReservationUnit $stay): ConciergeBillData
    {
        $stay->loadMissing('property.settings');

        $balance = $this->balance($stay);
        $due = $balance->isPositive() ? $balance : BigDecimal::zero();
        $opensOn = $this->stays->checkOutWindow($stay) ? null : $stay->departure_date->toDateString();

        return new ConciergeBillData(
            lines: $this->lines($stay),
            balance: (string) $balance->toScale(2, RoundingMode::HalfUp),
            due: (string) $due->toScale(2, RoundingMode::HalfUp),
            credit: (string) ($balance->isNegative() ? $balance->negated() : BigDecimal::zero())->toScale(2, RoundingMode::HalfUp),
            currency: $stay->property->settings->currency->getCurrencyCode(),
            canPay: $due->isPositive(),
            canCheckOut: $balance->isZero() && $opensOn === null,
            checkOutOpensOn: $opensOn,
            checkOutAsked: $this->checkOut->open($stay) !== null,
        );
    }

    /**
     * What the guest still owes, as a decimal string with two places. `0.00` when nothing is owed or the guest has a credit.
     */
    public function due(ReservationUnit $stay): string
    {
        $balance = $this->balance($stay);

        return (string) ($balance->isPositive() ? $balance : BigDecimal::zero())->toScale(2, RoundingMode::HalfUp);
    }

    private function balance(ReservationUnit $stay): BigDecimal
    {
        return $this->settlement->folios($stay)->reduce(
            fn (BigDecimal $sum, $folio): BigDecimal => $sum->plus($this->folioBalance->forFolio($folio)),
            BigDecimal::zero(),
        );
    }

    /**
     * @return list<ConciergeBillLineData>
     */
    private function lines(ReservationUnit $stay): array
    {
        $lines = FolioLine::query()
            ->whereIn('folio_id', $this->settlement->folios($stay)->pluck('id'))
            ->orderBy('service_date')
            ->orderBy('id')
            ->get()
            ->map(fn (FolioLine $line): ConciergeBillLineData => ConciergeBillLineData::fromLine($line))
            ->all();

        return array_values($lines);
    }
}
