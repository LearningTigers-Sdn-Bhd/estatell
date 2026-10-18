<?php

namespace App\Actions\Reservations;

use App\Enums\BookingSourceIcon;
use App\Enums\BookingSourceType;
use App\Enums\SwatchColor;
use App\Models\BookingSource;
use App\Models\OtaChannel;
use App\Models\User;
use App\Services\Reservations\BookingSourceUsage;
use Illuminate\Support\Facades\DB;

class UpdateBookingSource
{
    public function __construct(private BookingSourceUsage $usage) {}

    /**
     * Save a source. Only the keys present in `$attributes` change.
     *
     * A system row accepts only the badge. A used source keeps its type and OTA channel. A source turned on again goes
     * to the end of the active list. Returns false, without writing or logging, when a locked field would change, the
     * name is taken ignoring case, the channel is missing, off or already has a row, or the type is not one staff can set.
     * A save that changes nothing returns true and is not logged.
     *
     * @param  array<string, mixed>  $attributes  `name`, `type`, `ota_channel_id`, `is_active`, `badge_icon`, `badge_letters`, `badge_color`.
     */
    public function __invoke(User $actor, BookingSource $source, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $source, $attributes): bool {
            $source = BookingSource::query()->lockForUpdate()->findOrFail($source->id);
            $changes = [];

            $name = array_key_exists('name', $attributes) ? trim((string) $attributes['name']) : $source->name;
            $type = array_key_exists('type', $attributes) ? BookingSourceType::tryFrom($attributes['type'] instanceof BookingSourceType ? $attributes['type']->value : (string) $attributes['type']) : $source->type;
            $channelId = array_key_exists('ota_channel_id', $attributes) ? ($attributes['ota_channel_id'] === null ? null : (int) $attributes['ota_channel_id']) : $source->ota_channel_id;
            $active = array_key_exists('is_active', $attributes) ? (bool) $attributes['is_active'] : $source->is_active;

            if ($type === null) {
                return false;
            }

            if ($type === BookingSourceType::Direct) {
                $channelId = null;
            }

            $identityChanged = $name !== $source->name || $active !== $source->is_active;
            $kindChanged = $type !== $source->type || $channelId !== $source->ota_channel_id;

            if ($source->is_system && ($identityChanged || $kindChanged)) {
                return false;
            }

            if ($kindChanged) {
                if ($this->usage->isUsed($source) || ! $type->staffCanAdd() || ! $this->channelIsFree($source, $type, $channelId)) {
                    return false;
                }
            }

            if ($name === '' || ($name !== $source->name && $this->nameTaken($source, $name))) {
                return false;
            }

            if ($name !== $source->name) {
                $changes[] = 'name';
            }

            if ($type !== $source->type) {
                $changes[] = 'type';
            }

            if ($channelId !== $source->ota_channel_id) {
                $changes[] = 'ota_channel_id';
            }

            if (! $source->is_active && $active) {
                $source->sort_order = ((int) BookingSource::query()->where('property_id', $source->property_id)->where('is_system', false)->where('is_active', true)->max('sort_order')) + 1;
            }

            $source->name = $name;
            $source->type = $type;
            $source->ota_channel_id = $channelId;
            $source->is_active = $active;

            $this->applyBadge($source, $attributes);

            $changes = array_values(array_unique([...$changes, ...array_keys($source->getDirty())]));
            $changes = array_values(array_diff($changes, ['sort_order', 'updated_at']));

            if (! $source->isDirty()) {
                return true;
            }

            $source->save();

            activity()
                ->performedOn($source)
                ->causedBy($actor)
                ->event('booking_source.updated')
                ->withProperties(['property_id' => $source->property_id, 'changed' => $changes])
                ->log('Booking source updated');

            return true;
        });
    }

    /**
     * Setting an icon clears the letters, and setting letters clears the icon, so the badge stays one or the other.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function applyBadge(BookingSource $source, array $attributes): void
    {
        $icon = $attributes['badge_icon'] ?? null;
        $letters = $attributes['badge_letters'] ?? null;

        if ($icon !== null) {
            $source->badge_icon = $icon instanceof BookingSourceIcon ? $icon : BookingSourceIcon::from((string) $icon);
            $source->badge_letters = null;
        } elseif ($letters !== null) {
            $source->badge_letters = strtoupper((string) $letters);
            $source->badge_icon = null;
        }

        if (isset($attributes['badge_color'])) {
            $color = $attributes['badge_color'];
            $source->badge_color = $color instanceof SwatchColor ? $color : SwatchColor::from((string) $color);
        }
    }

    private function nameTaken(BookingSource $source, string $name): bool
    {
        return BookingSource::query()
            ->where('property_id', $source->property_id)
            ->whereKeyNot($source->id)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->exists();
    }

    /**
     * A real channel has one row per property; Other has many. An OTA row needs an active channel.
     */
    private function channelIsFree(BookingSource $source, BookingSourceType $type, ?int $channelId): bool
    {
        if (! $type->requiresOtaChannel()) {
            return $channelId === null;
        }

        $channel = OtaChannel::query()->active()->whereKey($channelId)->first();

        if ($channel === null) {
            return false;
        }

        return $channel->is_other || ! BookingSource::query()
            ->where('property_id', $source->property_id)
            ->where('ota_channel_id', $channel->id)
            ->whereKeyNot($source->id)
            ->exists();
    }
}
