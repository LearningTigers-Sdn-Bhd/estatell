<?php

namespace App\Services\Kiosk;

use App\Enums\PasscodeStatus;
use App\Enums\UnitStatus;
use App\Models\DoorCard;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\DoorLocks\CardNumber;
use App\Services\DoorLocks\StayByCard;
use App\Services\Folio\FolioBalance;
use App\Services\FrontDesk\CheckOutSettlement;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;

class KioskCheckOut
{
    public function __construct(
        private KioskStays $stays,
        private StayByCard $stayByCard,
        private CardNumber $cardNumber,
        private CheckOutSettlement $settlement,
        private FolioBalance $balance,
    ) {}

    /** @return array{balance: array{amount_minor: int, currency: string}, settled: bool} */
    public function folio(ReservationUnit $stay): array
    {
        $balances = $this->settlement->folios($stay)->map(fn ($folio): BigDecimal => BigDecimal::of($this->balance->forFolio($folio)));
        $total = $balances->reduce(fn (BigDecimal $sum, BigDecimal $amount): BigDecimal => $sum->plus($amount), BigDecimal::zero());
        $money = Money::of($total, $stay->property->settings->currency);

        return [
            'balance' => ['amount_minor' => $money->getMinorAmount()->toInt(), 'currency' => $money->getCurrency()->getCurrencyCode()],
            'settled' => $balances->every(fn (BigDecimal $amount): bool => $amount->isZero()),
        ];
    }

    /** @return array{known: bool, stay_id?: int, room_number?: string|null, settled?: bool} */
    public function lookup(Property $property, string $uid): array
    {
        $stay = $this->stayByCard->find($property, $uid);

        if ($stay === null || $stay->status !== UnitStatus::CheckedIn || ! $this->stays->checkOutWindow($stay)) {
            return ['known' => false];
        }

        return ['known' => true, 'stay_id' => $stay->id, 'room_number' => $this->stays->roomNumber($stay, checkOut: true), 'settled' => $this->folio($stay)['settled']];
    }

    public function ownsCard(ReservationUnit $stay, string $uid): bool
    {
        $number = $this->cardNumber->fromUid($uid);

        return $number !== null && DoorCard::query()->where('property_id', $stay->property_id)
            ->where('reservation_unit_id', $stay->id)->where('card_number', $number)->exists();
    }

    /** @return Collection<int, DoorCard> */
    public function cards(ReservationUnit $stay): Collection
    {
        return DoorCard::query()->where('property_id', $stay->property_id)->where('reservation_unit_id', $stay->id)
            ->whereIn('status', [PasscodeStatus::Active, PasscodeStatus::Pending, PasscodeStatus::Failed])
            ->with('doorLock.property.doorLockConnection')->orderBy('id')->get();
    }
}
