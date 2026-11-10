<?php

namespace App\Services\Boats;

use App\Data\Boats\BoatBoardGroupData;
use App\Data\Reports\BoatManifestRow;
use App\Data\Reports\ReportFilters;
use App\Enums\BoatDirection;
use App\Models\BoatDeparture;
use App\Models\Property;
use App\Services\Reports\BoatManifestReport;
use Carbon\CarbonImmutable;

/**
 * The groups of the Boats board for one date and direction: one per sailing in time order, then the charter and own boat
 * trips, then the units with no boat set. It reads the same rows as the Boat manifest (`BoatManifestReport`) and the same
 * sailings (`DateSailings`). Reads only. See docs/24-boats/05-boats-board.md.
 */
class BoatBoard
{
    public function __construct(
        private BoatManifestReport $manifest,
        private DateSailings $dateSailings,
        private SailingLabels $labels,
    ) {}

    /**
     * @param  'in'|'out'|'both'  $direction
     * @return list<BoatBoardGroupData>
     */
    public function groups(Property $property, CarbonImmutable $date, string $direction): array
    {
        $both = $direction === 'both';
        $directions = $both ? [BoatDirection::Out, BoatDirection::In] : [BoatDirection::from($direction)];
        $rows = $this->manifest->rows($property, new ReportFilters(
            from: $date->toDateString(),
            to: $date->toDateString(),
            direction: $both ? null : $directions[0],
            bothDirections: $both,
        ));

        $bySailing = [];
        $frontDesk = [];
        $notSet = [];

        foreach ($rows as $row) {
            match (true) {
                $row->not_set => $notSet[] = $row,
                $row->sailing_key === null => $frontDesk[] = $row,
                default => $bySailing[$row->sailing_key][] = $row,
            };
        }

        $all = collect($this->dateSailings->forDate($property, $date));
        $stored = $all->map(fn (array $list): bool => collect($list)->contains(fn (DateSailing $sailing): bool => $sailing->sailing !== null));

        $groups = [];

        $sailings = $all->flatten()
            ->filter(fn (DateSailing $sailing): bool => in_array($sailing->departure->direction, $directions, true))
            ->sortBy(fn (DateSailing $sailing): array => [$sailing->leavesAt, $sailing->sortId()])
            ->values();

        foreach ($sailings as $sailing) {
            $group = $bySailing[$sailing->key()] ?? [];

            if ($group === [] && ! $sailing->departure->active) {
                continue;
            }

            $groups[] = $this->sailingGroup($sailing, $group, $stored[$sailing->departure->id] ?? false);
        }

        if ($frontDesk !== []) {
            $groups[] = $this->plainGroup('front-desk', 'front_desk', __('Charter and own boat'), $frontDesk);
        }

        if ($notSet !== []) {
            $groups[] = $this->plainGroup('not-set', 'not_set', __('Boat not set: follow up'), $notSet);
        }

        return $groups;
    }

    public function hasDepartures(Property $property): bool
    {
        return BoatDeparture::query()->where('property_id', $property->id)->exists();
    }

    /**
     * @param  list<BoatManifestRow>  $rows
     */
    private function sailingGroup(DateSailing $sailing, array $rows, bool $hasStored): BoatBoardGroupData
    {
        $taken = array_sum(array_map(fn (BoatManifestRow $row): int => $row->total, $rows));

        return new BoatBoardGroupData(
            key: $sailing->key(),
            kind: 'sailing',
            direction: $sailing->departure->direction->value,
            directionLabel: $sailing->departure->direction->label(),
            time: $sailing->leavesAt,
            title: $sailing->departure->direction->label().' '.$sailing->leavesAt,
            times: $this->labels->times($sailing),
            boat: $this->labels->boat($sailing),
            inCharge: $sailing->inCharge()?->name,
            assistants: $sailing->assistants()->pluck('name')->all(),
            taken: $taken,
            seats: $sailing->seats,
            departureId: $sailing->departure->id,
            sailingId: $sailing->sailing?->id,
            hasStored: $hasStored,
            canRemove: $sailing->sailing !== null && ! $sailing->isMain && $taken === 0,
            boatId: $sailing->boat?->id,
            inChargeId: $sailing->inCharge()?->id,
            assistantIds: $sailing->assistants()->pluck('id')->map(intval(...))->values()->all(),
            rows: $rows,
        );
    }

    /**
     * @param  list<BoatManifestRow>  $rows
     */
    private function plainGroup(string $key, string $kind, string $title, array $rows): BoatBoardGroupData
    {
        return new BoatBoardGroupData(
            key: $key,
            kind: $kind,
            direction: null,
            directionLabel: null,
            time: null,
            title: $title,
            times: null,
            boat: null,
            inCharge: null,
            assistants: [],
            taken: array_sum(array_map(fn (BoatManifestRow $row): int => $row->total, $rows)),
            seats: null,
            departureId: null,
            sailingId: null,
            hasStored: false,
            canRemove: false,
            boatId: null,
            inChargeId: null,
            assistantIds: [],
            rows: $rows,
        );
    }
}
