<?php

namespace App\Services\Boats;

/**
 * The words that name a sailing, shared by the manifest page, its PDF and its spreadsheet (and the boats board later), so
 * they all name it the same way. Reads only. See docs/24-boats/03-boat-manifest.md#boats-and-crew.
 */
class SailingLabels
{
    /**
     * "Boat in · 09:00 Kuala Besut jetty → 09:45 (changed) · MV Sand Bay 2 · Din (in charge), Razak". The direction shows with
     * `$withDirection`. The title ends after the times when no boat or crew is set.
     */
    public function title(DateSailing $sailing, bool $withDirection = false): string
    {
        return implode(' · ', array_filter([
            $withDirection ? $sailing->direction->label() : null,
            $this->times($sailing),
            $this->boat($sailing),
            $this->crew($sailing),
        ]));
    }

    /** "09:00 Kuala Besut jetty → 09:45", with " (changed)" when the sailing has its own time. */
    public function times(DateSailing $sailing): string
    {
        $text = implode(' ', array_filter([$sailing->leavesAt, $sailing->pickupPoint])).' → '.$sailing->arrivesAt;

        return $sailing->timeChanged ? $text.' '.__('(changed)') : $text;
    }

    public function boat(DateSailing $sailing): ?string
    {
        return $sailing->boat?->name;
    }

    /** "Din (in charge), Razak": the one in charge first, then the assistants. Null with nobody on the crew. */
    public function crew(DateSailing $sailing): ?string
    {
        $names = array_filter([
            $sailing->inCharge() === null ? null : __(':name (in charge)', ['name' => $sailing->inCharge()->name]),
            ...$sailing->assistants()->pluck('name')->all(),
        ]);

        return $names === [] ? null : implode(', ', $names);
    }
}
