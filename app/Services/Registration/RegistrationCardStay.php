<?php

namespace App\Services\Registration;

use App\Enums\RegistrationCardField;
use App\Models\Property;
use App\Models\UnitGuest;
use App\Models\UnitNight;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * The stay and price lines of a registration card, as text. The guest does not fill these in.
 * It reads the unit's nights and writes nothing.
 */
class RegistrationCardStay
{
    /**
     * @return array<string, string> keyed by `RegistrationCardField` value, for every Stay and Prices field
     */
    public function valuesFor(UnitGuest $unitGuest, Property $property): array
    {
        $unit = $unitGuest->reservationUnit()->with(['reservation', 'roomType'])->firstOrFail();
        $nights = $unit->nights()->orderBy('date')->get();
        $count = max((int) $unit->arrival_date->diffInDays($unit->departure_date), 1);
        $currency = $property->settings?->currency->getCurrencyCode() ?? 'MYR';
        $total = Money::of($nights->sum(fn (UnitNight $night): string => $night->amount) ?: '0', $currency);

        return [
            RegistrationCardField::BookingNumber->value => $unit->reservation->reference,
            RegistrationCardField::CheckIn->value => $unit->arrival_date->format('d M Y').', '.substr($unit->arrival_time, 0, 5),
            RegistrationCardField::CheckOut->value => $unit->departure_date->format('d M Y').', '.substr($unit->departure_time, 0, 5),
            RegistrationCardField::Nights->value => (string) $count,
            RegistrationCardField::GuestCount->value => $unit->children > 0
                ? __('Adults :adults, children :children', ['adults' => $unit->adults, 'children' => $unit->children])
                : __('Adults :adults', ['adults' => $unit->adults]),
            RegistrationCardField::Room->value => $this->room($nights->first(), $unit->roomType->name),
            RegistrationCardField::RatePerNight->value => $currency.' '.$total->dividedBy($count, RoundingMode::HalfUp)->getAmount(),
            RegistrationCardField::TotalCharges->value => $currency.' '.$total->getAmount(),
        ];
    }

    private function room(?UnitNight $firstNight, string $roomTypeName): string
    {
        $room = $firstNight?->room_id !== null
            ? $firstNight->room()->first()?->number
            : $firstNight?->bed()->with('room')->first()?->room->number;

        return $room === null ? $roomTypeName : "{$roomTypeName} · {$room}";
    }
}
