<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * What the Move charges page shows for a choice: the posted balance of every Open folio before and after, the
 * target's Projected figure before and after, the scheduled choices per room, the scheduled charges that land on the
 * target, and the notes. `chargeCount` counts the posted charges (parents), `amount` is what leaves the sources (charges
 * with their tax and discount children) and `scheduleCount` the rooms with a scheduled choice. `refusal` is a message
 * when the choice cannot be saved as it is (more than 200 charges). It writes nothing.
 */
class MovePreviewData extends Data
{
    /**
     * @param  list<MoveBalanceData>  $balances
     * @param  list<MoveScheduleData>  $schedules
     * @param  list<MoveIncomingScheduleData>  $incoming
     * @param  list<string>  $notes
     */
    public function __construct(
        public int $targetId,
        public array $balances,
        public string $projectedBefore,
        public string $projectedAfter,
        public array $schedules,
        public array $incoming,
        public array $notes,
        public int $chargeCount,
        public string $amount,
        public int $scheduleCount,
        public ?string $refusal,
    ) {}
}
