<?php

namespace App\Data\Housekeeping;

use App\Data\Shared\BadgeData;
use App\Enums\HousekeepingStay;
use Spatie\LaravelData\Data;

/**
 * A dorm's row on the housekeeping board in counts, never one guest: each bed is its own stay, so the guest, the dates
 * and the stay badge of one bed would read as one stay for the room. `occupancy` is "6 of 8 beds tonight", `movements`
 * the stays of today with their count ("1 Due out", "2 Arriving", "5 In house", "1 Checked out"; none at zero),
 * `arrivals` "2 from 15:00" (still to arrive, from the earliest time) or "All arrived", and `departures` "1 by 11:00"
 * (still to leave, by the latest time) or "2 left". The board, the printed sheet and the spreadsheet read the same
 * words. See docs/19-housekeeping/04-dorm-beds.md#board.
 */
class HousekeepingDormData extends Data
{
    /** The counts of a dorm nobody stays in or leaves today. */
    public const array NO_COUNTS = ['dueOut' => 0, 'checkedOut' => 0, 'arriving' => 0, 'arrived' => 0, 'inHouse' => 0, 'tonight' => 0, 'firstArrivalTime' => null, 'lastDepartureTime' => null];

    /**
     * @param  list<BadgeData>  $movements
     */
    public function __construct(
        public int $bedsTonight,
        public int $beds,
        public string $occupancy,
        public array $movements,
        public ?string $arrivals,
        public ?string $departures,
    ) {}

    /**
     * @param  array{dueOut: int, checkedOut: int, arriving: int, arrived: int, inHouse: int, tonight: int, firstArrivalTime: ?string, lastDepartureTime: ?string}  $counts
     */
    public static function fromCounts(array $counts, int $beds): self
    {
        $movements = [];

        foreach ([
            [HousekeepingStay::DueOut, $counts['dueOut']],
            [HousekeepingStay::Arriving, $counts['arriving']],
            [HousekeepingStay::InHouse, $counts['inHouse']],
            [HousekeepingStay::CheckedOut, $counts['checkedOut']],
        ] as [$stay, $count]) {
            if ($count > 0) {
                $movements[] = new BadgeData($stay->value, "{$count} {$stay->label()}", $stay->tone());
            }
        }

        $arrivals = match (true) {
            $counts['arriving'] > 0 => $counts['firstArrivalTime'] === null
                ? (string) $counts['arriving']
                : __(':count from :time', ['count' => $counts['arriving'], 'time' => $counts['firstArrivalTime']]),
            $counts['arrived'] > 0 => __('All arrived'),
            default => null,
        };

        $departures = match (true) {
            $counts['dueOut'] > 0 => $counts['lastDepartureTime'] === null
                ? (string) $counts['dueOut']
                : __(':count by :time', ['count' => $counts['dueOut'], 'time' => $counts['lastDepartureTime']]),
            $counts['checkedOut'] > 0 => trans_choice(':count left|:count left', $counts['checkedOut']),
            default => null,
        };

        return new self(
            bedsTonight: $counts['tonight'],
            beds: $beds,
            occupancy: trans_choice(':taken of :beds bed tonight|:taken of :beds beds tonight', $beds, ['taken' => $counts['tonight'], 'beds' => $beds]),
            movements: $movements,
            arrivals: $arrivals,
            departures: $departures,
        );
    }
}
