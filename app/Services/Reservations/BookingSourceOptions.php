<?php

namespace App\Services\Reservations;

use App\Data\Reservations\BookingSourceOptionData;
use App\Enums\BookingSourceType;
use App\Models\BookingSource;
use App\Models\Property;
use Illuminate\Support\Collection;

/**
 * The booking sources the booking form and the reservations list filter offer, grouped by type: Direct, OTA, Company,
 * Walk-in. See docs/12-reservations/11-booking-sources.md.
 */
class BookingSourceOptions
{
    /**
     * The sources the booking form offers. Walk-in mode offers only the Walk-in source. New booking offers the active
     * Direct and OTA sources, and Company / Travel agent when the staff member may use companies and the property has an
     * Active company.
     *
     * @return list<BookingSourceOptionData>
     */
    public function forBooking(Property $property, bool $walkIn, bool $canUseCompany): array
    {
        $types = $walkIn
            ? [BookingSourceType::WalkIn]
            : [BookingSourceType::Direct, BookingSourceType::Ota, ...($canUseCompany ? [BookingSourceType::Company] : [])];

        return $this->data($this->sources($property)->filter(
            fn (BookingSource $source): bool => $source->is_active && in_array($source->type, $types, true),
        ));
    }

    /**
     * Every source of the property for the list filter. Inactive sources stay, because old bookings use them.
     *
     * @return list<BookingSourceOptionData>
     */
    public function forFilter(Property $property): array
    {
        return $this->data($this->sources($property));
    }

    /**
     * @return Collection<int, BookingSource>
     */
    private function sources(Property $property): Collection
    {
        return BookingSource::query()->where('property_id', $property->id)->ordered()->get()
            ->sortBy([
                fn (BookingSource $a, BookingSource $b): int => $a->type->listOrder() <=> $b->type->listOrder(),
                fn (BookingSource $a, BookingSource $b): int => (int) ! $a->is_active <=> (int) ! $b->is_active,
            ])
            ->values();
    }

    /**
     * @param  Collection<int, BookingSource>  $sources
     * @return list<BookingSourceOptionData>
     */
    private function data(Collection $sources): array
    {
        return array_values($sources->map(BookingSourceOptionData::fromModel(...))->all());
    }
}
