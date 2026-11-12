<?php

namespace App\Data\Reservations;

use App\Enums\BookingSourceType;
use App\Enums\TaxWaiverReason;
use App\Models\Reservation;
use App\Models\Tax;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

/**
 * What the Taxes paid elsewhere Dialog needs for one unit: the enabled taxes not waived yet, the reasons, and the
 * values it starts with. `paidTo` is the OTA booking source name or the company name, and `reference` the booking's
 * External reference. Sent only to a user who may waive taxes. See docs/06-catalogues/03-taxes.md#tax-waivers.
 */
class UnitTaxWaiverFormData extends Data
{
    /**
     * @param  list<array{value: string, label: string}>  $taxes
     * @param  list<array{value: string, label: string}>  $reasons
     */
    public function __construct(
        public array $taxes,
        public array $reasons,
        public ?string $paidTo,
        public ?string $reference,
    ) {}

    /**
     * @param  Collection<int, Tax>  $taxes  The property's enabled taxes not waived for the unit.
     */
    public static function forReservation(Reservation $reservation, Collection $taxes): self
    {
        $source = $reservation->bookingSource;
        $paidTo = match ($source?->type) {
            BookingSourceType::Ota => $source->name,
            BookingSourceType::Company => $reservation->company?->name,
            default => null,
        };

        return new self(
            array_values($taxes->map(fn (Tax $tax): array => ['value' => (string) $tax->id, 'label' => $tax->name])->all()),
            TaxWaiverReason::options(),
            $paidTo,
            $reservation->external_reference,
        );
    }
}
