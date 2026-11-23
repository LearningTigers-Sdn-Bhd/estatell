<?php

namespace App\Services\Facilities;

use App\Models\FacilityBooking;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\TransactionCode;
use App\Services\Catalogues\TaxCalculationResult;
use App\Services\Catalogues\TaxCalculator;
use Brick\Money\Money;

/**
 * What one facility booking charges: the facility's transaction code, the unit price, how many units of it the rate
 * basis gives, the tax, and the line description "{facility} · {from}–{to}". `PostFacilityBooking` posts it and
 * `ScheduledFolioRows` shows it, so the two always agree. Taxes use the nationality of the folio's payer guest, else
 * the event's payer, else the reservation's booker, as the Book facility quote does. Null for a booking charged by
 * hand (no price). See docs/33-facilities/04-facility-bookings.md#posting.
 */
class FacilityBookingCharge
{
    public function __construct(private TaxCalculator $taxCalculator) {}

    /**
     * Expects `facility.chargeItem.transactionCode`, `folio.payerGuest`, `event.payerGuest` and `reservation.booker`
     * to be loaded, and the property's `settings`.
     *
     * @return array{code: TransactionCode, description: string, quantity: int, unitAmount: Money, tax: TaxCalculationResult}|null
     */
    public function for(Property $property, FacilityBooking $booking): ?array
    {
        if ($booking->unit_amount === null) {
            return null;
        }

        $facility = $booking->facility;
        $code = $facility->chargeItem->transactionCode;
        $quantity = $facility->rate_basis->quantity($booking->starts_at, $booking->ends_at, $booking->quantity);
        $unitAmount = Money::of($booking->unit_amount, $property->settings->currency->getCurrencyCode());
        $nationality = $booking->folio->payerGuest->nationality_code
            ?? $booking->event?->payerGuest?->nationality_code
            ?? $booking->reservation?->booker?->nationality_code;

        return [
            'code' => $code,
            'description' => self::description($booking),
            'quantity' => $quantity,
            'unitAmount' => $unitAmount,
            'tax' => $this->taxCalculator->calculate($property, $code, $unitAmount->multipliedBy($quantity), (string) $quantity, $nationality),
        ];
    }

    /**
     * The relations `for()` needs, for `with()` or `load()`.
     *
     * @return list<string>
     */
    public static function relations(): array
    {
        return ['facility.chargeItem.transactionCode', 'folio.payerGuest', 'event.payerGuest', 'reservation.booker'];
    }

    /**
     * The ids of the given bookings that have a posted `facility:{id}` line.
     *
     * @param  iterable<FacilityBooking>  $bookings
     * @return array<int, true> keyed by booking id
     */
    public function postedIds(iterable $bookings): array
    {
        $keys = [];

        foreach ($bookings as $booking) {
            $keys[] = 'facility:'.$booking->id;
        }

        return $keys === [] ? [] : FolioLine::query()
            ->whereIn('idempotency_key', $keys)
            ->pluck('idempotency_key')
            ->mapWithKeys(fn (string $key): array => [(int) substr($key, 9) => true])
            ->all();
    }

    public static function description(FacilityBooking $booking): string
    {
        return $booking->facility->name.' · '.substr($booking->starts_at, 0, 5).'–'.substr($booking->ends_at, 0, 5);
    }
}
