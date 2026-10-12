<?php

namespace App\Actions\Housekeeping;

use App\Data\Housekeeping\HousekeepingBulkResultData;
use App\Enums\HousekeepingBulkAction;
use App\Enums\HousekeepingRefusal;
use App\Enums\HousekeepingStatus;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Collection;

class BulkUpdateRooms
{
    private const REMARK_LIMIT = 500;

    public function __construct(
        private UpdateRoomStatus $updateRoomStatus,
        private AssignHousekeeper $assignHousekeeper,
        private SetRoomPriority $setRoomPriority,
        private SetRoomRemark $setRoomRemark,
        private SetNeedsMaintenance $setNeedsMaintenance,
    ) {}

    /**
     * Run one housekeeping Action on every room. It is not one transaction: a refused room is skipped and the others
     * still run. A room that is already as wanted counts as done. Expects each room's `status` loaded.
     *
     * @param  Collection<int, Room>  $rooms
     * @param  User|null  $assignee  For `Assign`, null clears the assignment.
     * @param  string|null  $remark  For `AddRemark`, the text added after the room's remark.
     */
    public function __invoke(User $actor, Collection $rooms, HousekeepingBulkAction $action, ?User $assignee = null, ?string $remark = null): HousekeepingBulkResultData
    {
        $done = 0;
        $skipped = [];

        foreach ($rooms as $room) {
            $refusal = $this->run($actor, $room, $action, $assignee, $remark);

            if ($refusal === null) {
                $done++;
            } else {
                $skipped[] = ['number' => $room->number, 'refusal' => $refusal];
            }
        }

        return new HousekeepingBulkResultData($done, $skipped);
    }

    private function run(User $actor, Room $room, HousekeepingBulkAction $action, ?User $assignee, ?string $remark): ?HousekeepingRefusal
    {
        switch ($action) {
            case HousekeepingBulkAction::MarkClean:
                return ($this->updateRoomStatus)($actor, $room, HousekeepingStatus::Clean);
            case HousekeepingBulkAction::MarkInspected:
                return ($this->updateRoomStatus)($actor, $room, HousekeepingStatus::Inspected);
            case HousekeepingBulkAction::Assign:
                ($this->assignHousekeeper)($actor, $room, $assignee);
                break;
            case HousekeepingBulkAction::SetPriority:
                ($this->setRoomPriority)($actor, $room, true, $room->status->priority_note);
                break;
            case HousekeepingBulkAction::ClearPriority:
                ($this->setRoomPriority)($actor, $room, false, null);
                break;
            case HousekeepingBulkAction::AddRemark:
                $existing = $room->status->remark;
                $combined = $existing === null || $existing === '' ? (string) $remark : $existing."\n".$remark;
                ($this->setRoomRemark)($actor, $room, mb_substr($combined, 0, self::REMARK_LIMIT));
                break;
            case HousekeepingBulkAction::NeedsMaintenance:
                ($this->setNeedsMaintenance)($actor, $room, true);
                break;
        }

        return null;
    }
}
