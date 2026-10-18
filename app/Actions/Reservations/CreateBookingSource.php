<?php

namespace App\Actions\Reservations;

use App\Enums\BookingSourceIcon;
use App\Enums\BookingSourceType;
use App\Enums\SwatchColor;
use App\Models\BookingSource;
use App\Models\OtaChannel;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateBookingSource
{
    /**
     * Add a Direct or OTA source at the end of the property's active list. Without badge fields, a Direct source gets
     * the Globe icon on Gray, and an OTA source its first letter on the channel's color.
     *
     * Returns null when the type is not one staff can add, the channel is missing or off, a real channel already has a
     * row, or the name is taken ignoring case. Nothing is written or logged then.
     *
     * @param  array<string, mixed>  $attributes  `name` and `type` are required; `ota_channel_id` and the badge fields are optional.
     */
    public function __invoke(Property $property, User $actor, array $attributes): ?BookingSource
    {
        return DB::transaction(function () use ($property, $actor, $attributes): ?BookingSource {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $type = BookingSourceType::from($attributes['type'] instanceof BookingSourceType ? $attributes['type']->value : (string) $attributes['type']);
            $name = trim((string) $attributes['name']);

            if (! $type->staffCanAdd() || $name === '' || $this->nameTaken($property, $name)) {
                return null;
            }

            $channel = null;

            if ($type->requiresOtaChannel()) {
                $channel = OtaChannel::query()->active()->whereKey($attributes['ota_channel_id'] ?? null)->first();

                if ($channel === null || (! $channel->is_other && BookingSource::query()->where('property_id', $property->id)->where('ota_channel_id', $channel->id)->exists())) {
                    return null;
                }
            }

            $icon = $attributes['badge_icon'] ?? null;
            $letters = $attributes['badge_letters'] ?? null;

            if (($icon === null) === ($letters === null)) {
                $icon = $channel === null ? BookingSourceIcon::Globe : null;
                $letters = $channel === null ? null : $this->firstLetter($name);
            }

            $color = $attributes['badge_color'] ?? ($channel === null ? SwatchColor::Gray : SwatchColor::forOtaChannelCode($channel->code));

            $source = new BookingSource([
                'name' => $name,
                'type' => $type,
                'ota_channel_id' => $channel?->id,
                'is_active' => true,
                'sort_order' => ((int) BookingSource::query()->where('property_id', $property->id)->where('is_system', false)->where('is_active', true)->max('sort_order')) + 1,
                'badge_icon' => $icon,
                'badge_letters' => $letters,
                'badge_color' => $color,
            ]);
            $source->forceFill(['property_id' => $property->id, 'is_system' => false])->save();

            activity()
                ->performedOn($source)
                ->causedBy($actor)
                ->event('booking_source.created')
                ->withProperties(['property_id' => $property->id, 'booking_source_id' => $source->id, 'name' => $source->name, 'type' => $type->value])
                ->log("Booking source {$source->name} created");

            return $source;
        });
    }

    private function nameTaken(Property $property, string $name): bool
    {
        return BookingSource::query()->where('property_id', $property->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists();
    }

    private function firstLetter(string $name): string
    {
        preg_match('/[A-Za-z0-9]/', $name, $match);

        return strtoupper($match[0] ?? 'O');
    }
}
