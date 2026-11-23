<?php

namespace App\Enums;

/**
 * The status of an event. Tentative holds its bookings without blocking; Confirmed bookings block and post. Closing
 * the event folio completes a Confirmed event, and reopening the folio confirms it again. See
 * docs/33-facilities/03-events-and-event-folios.md.
 */
enum EventStatus: string
{
    case Tentative = 'tentative';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Tentative => __('Tentative'),
            self::Confirmed => __('Confirmed'),
            self::Completed => __('Completed'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Tentative => Tone::Warning,
            self::Confirmed => Tone::Info,
            self::Completed => Tone::Success,
            self::Cancelled => Tone::Danger,
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, match ($this) {
            self::Tentative => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Completed, self::Cancelled],
            self::Completed => [self::Confirmed],
            self::Cancelled => [],
        }, true);
    }

    /**
     * The statuses staff pick when they create an event.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function newEventOptions(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            [self::Tentative, self::Confirmed],
        );
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
