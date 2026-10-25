<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Timeline asks once when a Confirmed bar is dropped on new dates and a room or bed: the dates preview
 * (`dates`: totals, `restricted`, `error`), whether the target is free on every new night (`targetFree`, with the
 * first night it is not in `firstTakenNight`), whether the target can be picked and its room type is the unit's own or
 * one its rate plan sells with the same sell unit (`roomTypeAllowed`), and whether the Dialog must ask for a reason
 * (`reasonRequired`: the unit holds a room or bed now and the room changes). `error` is a refusal that stops the
 * drop before any Dialog (the unit is not Confirmed, or the dates preview failed).
 */
class UnitMoveStayPreviewData extends Data
{
    public function __construct(
        public ?UnitDatesPreviewData $dates,
        public bool $targetFree,
        public ?string $firstTakenNight,
        public bool $roomTypeAllowed,
        public bool $reasonRequired,
        public ?string $error,
    ) {}

    public static function refused(string $error): self
    {
        return new self(null, false, null, false, true, $error);
    }
}
