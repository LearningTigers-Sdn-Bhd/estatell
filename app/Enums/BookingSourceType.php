<?php

namespace App\Enums;

/**
 * What kind of source a booking came from. The type carries the rules; nothing else branches on a source name.
 * See docs/12-reservations/11-booking-sources.md.
 */
enum BookingSourceType: string
{
    case Direct = 'direct';
    case Ota = 'ota';
    case Company = 'company';
    case WalkIn = 'walk_in';

    public function label(): string
    {
        return match ($this) {
            self::Direct => __('Direct'),
            self::Ota => __('OTA'),
            self::Company => __('Company / Travel agent'),
            self::WalkIn => __('Walk-in'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Direct => Tone::Neutral,
            self::Ota => Tone::Info,
            self::Company => Tone::Warning,
            self::WalkIn => Tone::Success,
        };
    }

    /**
     * The types staff can add: Direct and OTA. Company and Walk-in are system rows.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            array_values(array_filter(self::cases(), fn (self $type): bool => $type->staffCanAdd())),
        );
    }

    public function staffCanAdd(): bool
    {
        return in_array($this, [self::Direct, self::Ota], true);
    }

    /**
     * The order of the groups in a source list.
     */
    public function listOrder(): int
    {
        return match ($this) {
            self::Direct => 1,
            self::Ota => 2,
            self::Company => 3,
            self::WalkIn => 4,
        };
    }

    public function requiresOtaChannel(): bool
    {
        return $this === self::Ota;
    }

    public function requiresExternalReference(): bool
    {
        return $this === self::Ota;
    }

    public function requiresCompany(): bool
    {
        return $this === self::Company;
    }

    public function isWalkIn(): bool
    {
        return $this === self::WalkIn;
    }
}
