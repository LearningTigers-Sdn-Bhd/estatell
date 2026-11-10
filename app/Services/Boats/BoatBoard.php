<?php

namespace App\Services\Boats;

use App\Data\Boats\BoatBoardGroupData;
use App\Data\Boats\BoatPickData;
use App\Data\Reports\BoatManifestRow;
use App\Data\Reports\ReportFilters;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Models\BoatDeparture;
use App\Models\Property;
use App\Services\Reports\BoatManifestReport;
use Carbon\CarbonImmutable;

/**
 * The groups of the Boats board for one date and direction: one per sailing in time order, then the charter trips, the own boat
 * trips, then the units with no boat set. Charter and Own boat always show, even empty, unless the date has no group at all
 * (the page then shows its empty state). Boat not set shows only with rows. It reads the same rows as the Boat manifest (`BoatManifestReport`) and the same
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
     * The groups of one tab: `both` (All), `in` or `out` (the sailings of that direction and its rows with no boat set) or
     * `other` (Charter and Own boat, shown even when empty).
     *
     * @param  'in'|'out'|'both'|'other'  $direction
     * @return list<BoatBoardGroupData>
     */
    public function groups(Property $property, CarbonImmutable $date, string $direction): array
    {
        $both = in_array($direction, ['both', 'other'], true);
        $others = $direction === 'other';
        $directions = $both ? [BoatDirection::Out, BoatDirection::In] : [BoatDirection::from($direction)];
        $rows = $this->manifest->rows($property, new ReportFilters(
            from: $date->toDateString(),
            to: $date->toDateString(),
            direction: $both ? null : $directions[0],
            bothDirections: $both,
            boatGroup: match ($direction) {
                'both' => null,
                'other' => 'other',
                default => 'scheduled',
            },
        ));

        $bySailing = [];
        $charter = [];
        $own = [];
        $notSet = [];

        foreach ($rows as $row) {
            match (true) {
                $row->not_set => $notSet[] = $row,
                $row->sailing_key === null && $row->mode === BoatMode::Charter->value => $charter[] = $row,
                $row->sailing_key === null => $own[] = $row,
                default => $bySailing[$row->sailing_key][] = $row,
            };
        }

        $all = collect($this->dateSailings->forDate($property, $date, withCancelled: true));
        $stored = $all->map(fn (array $list): bool => collect($list)->contains(fn (DateSailing $sailing): bool => $sailing->sailing !== null));
        $guestsByDeparture = [];

        foreach ($rows as $row) {
            if ($row->boat_departure_id !== null && ! $row->not_set) {
                $guestsByDeparture[$row->boat_departure_id] = ($guestsByDeparture[$row->boat_departure_id] ?? 0) + $row->total;
            }
        }

        $groups = [];

        $sailings = $all->map(fn (array $list): array => $this->visible($list))
            ->flatten()
            ->merge($this->dateSailings->extras($property, $date))
            ->filter(fn (DateSailing $sailing): bool => in_array($sailing->direction, $directions, true))
            ->sortBy(fn (DateSailing $sailing): array => [$sailing->leavesAt, $sailing->sortId()])
            ->values();

        foreach ($others ? [] : $sailings as $sailing) {
            $group = $bySailing[$sailing->key()] ?? [];

            if ($group === [] && $sailing->departure !== null && ! $sailing->departure->active) {
                continue;
            }

            $groups[] = $this->sailingGroup(
                $sailing,
                $group,
                $sailing->departure === null ? false : ($stored[$sailing->departure->id] ?? false),
                $guestsByDeparture[$sailing->departure?->id] ?? 0,
                $sailing->departure === null ? 1 : count($this->visible($all[$sailing->departure->id] ?? [])),
            );
        }

        if ($others || ($direction === 'both' && ($groups !== [] || $charter !== [] || $own !== [] || $notSet !== []))) {
            $groups[] = $this->plainGroup('charter', 'charter', BoatMode::Charter->label(), $charter);
            $groups[] = $this->plainGroup('own', 'own', BoatMode::Own->label(), $own);
        }

        if ($notSet !== []) {
            $groups[] = $this->plainGroup('not-set', 'not_set', __('Boat not set: follow up'), $notSet);
        }

        return $groups;
    }

    /**
     * The sailings of one departure the board shows: all of them, or only the first when the departure is cancelled for
     * the date, so a cancelled departure is one muted group with Undo.
     *
     * @param  list<DateSailing>  $list
     * @return list<DateSailing>
     */
    private function visible(array $list): array
    {
        $live = array_values(array_filter($list, fn (DateSailing $sailing): bool => ! $sailing->cancelled));

        return $live === [] ? array_slice($list, 0, 1) : $live;
    }

    /**
     * The boats of a date the Change boat Dialog lists, both directions, in time order: every running sailing of a
     * departure. Extra trips are left out, because a trip on one keeps its booked departure (Move to puts guests there).
     *
     * @return list<BoatPickData>
     */
    public function picks(Property $property, CarbonImmutable $date): array
    {
        return collect($this->groups($property, $date, 'both'))
            ->filter(fn (BoatBoardGroupData $group): bool => $group->kind === 'sailing' && ! $group->cancelled && ! $group->isExtra && $group->departureId !== null)
            ->map(fn (BoatBoardGroupData $group): BoatPickData => BoatPickData::fromGroup($group))
            ->values()
            ->all();
    }

    public function hasDepartures(Property $property): bool
    {
        return BoatDeparture::query()->where('property_id', $property->id)->exists();
    }

    /**
     * @param  list<BoatManifestRow>  $rows
     */
    private function sailingGroup(DateSailing $sailing, array $rows, bool $hasStored, int $departureGuests, int $departureBoats): BoatBoardGroupData
    {
        $taken = array_sum(array_map(fn (BoatManifestRow $row): int => $row->total, $rows));

        return new BoatBoardGroupData(
            key: $sailing->key(),
            kind: 'sailing',
            direction: $sailing->direction->value,
            directionLabel: $sailing->direction->label(),
            time: $sailing->leavesAt,
            title: $sailing->direction->label().' '.$sailing->leavesAt,
            times: $this->labels->times($sailing),
            boat: $this->labels->boat($sailing),
            inCharge: $sailing->inCharge()?->name,
            assistants: $sailing->assistants()->pluck('name')->all(),
            taken: $taken,
            seats: $sailing->seats,
            departureId: $sailing->departure?->id,
            sailingId: $sailing->sailing?->id,
            hasStored: $hasStored,
            canRemove: $sailing->sailing !== null && ! $sailing->isMain && $taken === 0,
            cancelled: $sailing->cancelled,
            isExtra: $sailing->isExtra(),
            departureGuests: $departureGuests,
            isMain: $sailing->isMain,
            departureBoats: $departureBoats,
            leavesAt: $sailing->leavesAt,
            arrivesAt: $sailing->arrivesAt,
            pickupPoint: $sailing->pickupPoint,
            timeChanged: $sailing->timeChanged,
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
            cancelled: false,
            isExtra: false,
            departureGuests: 0,
            isMain: false,
            departureBoats: 0,
            leavesAt: null,
            arrivesAt: null,
            pickupPoint: null,
            timeChanged: false,
            boatId: null,
            inChargeId: null,
            assistantIds: [],
            rows: $rows,
        );
    }
}
