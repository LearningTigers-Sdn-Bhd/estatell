<?php

namespace App\Actions\Reservations;

use App\Enums\BookingSourceType;
use App\Enums\SwatchColor;
use App\Models\BookingSource;
use App\Models\OtaChannel;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

class EnsureOtaBookingSource
{
    /**
     * Find or create the property's OTA source for a channel. A real channel has one row, found by its channel and named
     * after the channel. Other has one row per name, found ignoring case. A name taken by another row gets " (OTA)" and
     * then " (OTA 2)" to " (OTA 9)". A row that exists is returned as it is, so staff's choices are kept. Only a new row
     * is logged. See docs/12-reservations/11-booking-sources.md#defaults.
     */
    public function __invoke(Property $property, OtaChannel $channel, ?string $name = null): BookingSource
    {
        return DB::transaction(function () use ($property, $channel, $name): BookingSource {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $existing = $this->findExisting($property, $channel, $name);

            if ($existing !== null) {
                return $existing;
            }

            $name = $this->freeName($property, $channel, $name);

            $source = new BookingSource([
                'name' => $name,
                'type' => BookingSourceType::Ota,
                'ota_channel_id' => $channel->id,
                'is_active' => true,
                'sort_order' => $this->nextSortOrder($property),
                'badge_icon' => null,
                'badge_letters' => $this->badgeLetters($name),
                'badge_color' => SwatchColor::forOtaChannelCode($channel->code),
            ]);
            $source->forceFill(['property_id' => $property->id, 'is_system' => false])->save();

            activity()
                ->performedOn($property)
                ->event('booking_source.created')
                ->withProperties(['booking_source_id' => $source->id, 'name' => $source->name, 'channel' => $channel->code])
                ->log("Booking source {$source->name} created");

            return $source;
        });
    }

    private function findExisting(Property $property, OtaChannel $channel, ?string $name): ?BookingSource
    {
        $query = BookingSource::query()->where('property_id', $property->id)->where('ota_channel_id', $channel->id);

        if (! $channel->is_other) {
            return $query->first();
        }

        return $query->whereRaw('lower(name) = ?', [mb_strtolower($this->otherName($name))])->first();
    }

    /**
     * The name of a new row: the channel's name, or the typed name on Other (or "OTA" when blank).
     */
    private function freeName(Property $property, OtaChannel $channel, ?string $name): string
    {
        $base = $channel->is_other ? $this->otherName($name) : $channel->name;
        $base = trim(mb_substr($base, 0, 60));

        if (! $this->nameTaken($property, $base)) {
            return $base;
        }

        for ($number = 1; $number <= 9; $number++) {
            $suffix = $number === 1 ? ' (OTA)' : " (OTA {$number})";
            $candidate = trim(mb_substr($base, 0, 60 - mb_strlen($suffix))).$suffix;

            if (! $this->nameTaken($property, $candidate)) {
                return $candidate;
            }
        }

        return $base;
    }

    private function nameTaken(Property $property, string $name): bool
    {
        return BookingSource::query()->where('property_id', $property->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists();
    }

    private function otherName(?string $name): string
    {
        return trim((string) $name) !== '' ? trim((string) $name) : 'OTA';
    }

    private function nextSortOrder(Property $property): int
    {
        return ((int) BookingSource::query()->where('property_id', $property->id)->where('is_system', false)->max('sort_order')) + 1;
    }

    /**
     * The first letter or digit of the name, upper case. Non-Latin names get "O".
     */
    private function badgeLetters(string $name): string
    {
        preg_match('/[A-Za-z0-9]/', $name, $match);

        return strtoupper($match[0] ?? 'O');
    }
}
