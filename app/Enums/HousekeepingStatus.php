<?php

namespace App\Enums;

/**
 * The housekeeping state of a room, stored on `room_statuses.status`.
 * "Out of service" is not a status: it comes from an open room block. See docs/05-rooms/04-room-blocks-and-statuses.md.
 */
enum HousekeepingStatus: string
{
    case Clean = 'clean';
    case Dirty = 'dirty';
    case Inspected = 'inspected';

    public function label(): string
    {
        return match ($this) {
            self::Clean => __('Clean'),
            self::Dirty => __('Dirty'),
            self::Inspected => __('Inspected'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Clean => Tone::Success,
            self::Dirty => Tone::Warning,
            self::Inspected => Tone::Info,
        };
    }

    /**
     * Housekeeping moves Dirty > Clean > Inspected. Any room can become Dirty again.
     */
    public function canTransitionTo(self $status): bool
    {
        return in_array($status, match ($this) {
            self::Dirty => [self::Clean],
            self::Clean => [self::Inspected, self::Dirty],
            self::Inspected => [self::Dirty],
        }, true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
