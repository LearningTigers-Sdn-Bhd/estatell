<?php

namespace App\Data\Registration;

use App\Models\UnitGuest;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

/**
 * The thank-you screen after a guest signs. It names only the next guest, never the one who signed.
 */
class RegistrationCardDoneData extends Data
{
    /**
     * @param  array<string, string>  $labels  English text to the text in the signed language
     */
    public function __construct(
        public int $unitGuestId,
        public ?int $nextUnitGuestId,
        public ?string $nextGuestName,
        public ?int $nextPosition,
        public int $total,
        public string $locale,
        public array $labels,
    ) {}

    /**
     * @param  Collection<int, UnitGuest>  $required  the unit's guests that need a card, in walk order
     * @param  array<string, string>  $labels
     */
    public static function forWalk(UnitGuest $signed, ?UnitGuest $next, Collection $required, string $locale, array $labels): self
    {
        $position = $next === null ? false : $required->search(fn (UnitGuest $unitGuest): bool => $unitGuest->id === $next->id);

        return new self(
            unitGuestId: $signed->id,
            nextUnitGuestId: $next?->id,
            nextGuestName: $next?->guest->full_name,
            nextPosition: $position === false ? null : $position + 1,
            total: $required->count(),
            locale: $locale,
            labels: $labels,
        );
    }
}
