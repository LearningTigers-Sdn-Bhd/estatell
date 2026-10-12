<?php

namespace App\Enums;

/**
 * The status of one reservation unit. Every transition is one Action, checked by `canTransitionTo()`.
 * See docs/12-reservations/01-reservation-shape.md.
 */
enum UnitStatus: string
{
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case CheckedOut = 'checked_out';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => __('Confirmed'),
            self::CheckedIn => __('Checked in'),
            self::CheckedOut => __('Checked out'),
            self::Cancelled => __('Cancelled'),
            self::NoShow => __('No-show'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Confirmed => Tone::Info,
            self::CheckedIn => Tone::Success,
            self::CheckedOut => Tone::Neutral,
            self::Cancelled => Tone::Danger,
            self::NoShow => Tone::Warning,
        };
    }

    /**
     * Cancelled and no-show units can be reinstated to Confirmed. A checked-out unit is final.
     */
    public function canTransitionTo(self $status): bool
    {
        return in_array($status, match ($this) {
            self::Confirmed => [self::CheckedIn, self::Cancelled, self::NoShow],
            self::CheckedIn => [self::CheckedOut],
            self::CheckedOut => [],
            self::Cancelled, self::NoShow => [self::Confirmed],
        }, true);
    }

    /**
     * Undo check-in puts a CheckedIn unit back to Confirmed. It is not in `canTransitionTo()`, because that method also
     * decides Reinstate, which is for Cancelled and NoShow units only.
     */
    public function canUndoCheckIn(): bool
    {
        return $this === self::CheckedIn;
    }

    /**
     * Undo check-out puts a CheckedOut unit back to CheckedIn. A checked-out unit is otherwise final, so this is not in
     * `canTransitionTo()`.
     */
    public function canUndoCheckOut(): bool
    {
        return $this === self::CheckedOut;
    }

    /**
     * Whether a unit in this status takes its room or bed off sale on its nights.
     * Cancelled and no-show units release their nights. Checked-out units keep them, so past nights stay sold.
     */
    public function holdsInventory(): bool
    {
        return in_array($this, [self::Confirmed, self::CheckedIn, self::CheckedOut], true);
    }

    /**
     * @return list<self>
     */
    public static function holdingInventory(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->holdsInventory()));
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
