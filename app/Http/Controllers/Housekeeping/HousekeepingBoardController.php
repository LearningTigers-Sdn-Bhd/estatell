<?php

namespace App\Http\Controllers\Housekeeping;

use App\Actions\Housekeeping\AssignHousekeeper;
use App\Actions\Housekeeping\BulkUpdateRooms;
use App\Actions\Housekeeping\SetDoNotDisturb;
use App\Actions\Housekeeping\SetNeedsMaintenance;
use App\Actions\Housekeeping\SetRoomPriority;
use App\Actions\Housekeeping\SetRoomRemark;
use App\Actions\Housekeeping\UpdateRoomStatus;
use App\Data\Housekeeping\HousekeepingStaffData;
use App\Data\Rooms\RoomGroupData;
use App\Data\Shared\BadgeData;
use App\Enums\HousekeepingStatus;
use App\Enums\PageOrientation;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Housekeeping\AssignHousekeeperRequest;
use App\Http\Requests\Housekeeping\BulkUpdateRoomsRequest;
use App\Http\Requests\Housekeeping\SetDoNotDisturbRequest;
use App\Http\Requests\Housekeeping\SetNeedsMaintenanceRequest;
use App\Http\Requests\Housekeeping\SetRoomPriorityRequest;
use App\Http\Requests\Housekeeping\SetRoomRemarkRequest;
use App\Http\Requests\Housekeeping\UpdateRoomStatusRequest;
use App\Models\Property;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomGroup;
use App\Models\User;
use App\Services\Housekeeping\HousekeepingBoardBuilder;
use App\Services\Housekeeping\HousekeepingDate;
use App\Services\Reports\ReportLayoutResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HousekeepingBoardController extends Controller
{
    /**
     * Every active room for the board date. Filtering, grouping and sorting happen in the browser.
     */
    public function index(Request $request, Property $property, HousekeepingDate $housekeepingDate, HousekeepingBoardBuilder $builder, ReportLayoutResolver $layoutResolver): Response
    {
        $user = $request->user();
        $date = $housekeepingDate->for($property);
        $canUpdate = $user->can(PermissionEnum::HousekeepingUpdate->value);
        $canInspect = $user->can(PermissionEnum::HousekeepingInspect->value);
        $canBlock = $user->can(PermissionEnum::RoomsBlock->value);

        return Inertia::render('housekeeping/index', [
            'date' => $date->toDateString(),
            'rooms' => $builder->rows($property, $date, $canUpdate, $canInspect, $canBlock),
            'roomGroups' => $property->roomGroups()->orderBy('position')->orderBy('id')->get()
                ->map(fn (RoomGroup $group): RoomGroupData => RoomGroupData::fromModel($group)),
            'statuses' => array_map(fn (HousekeepingStatus $status): BadgeData => new BadgeData($status->value, $status->label(), $status->tone()), HousekeepingStatus::cases()),
            'staff' => $canUpdate ? $this->staff($property) : [],
            'layout' => $layoutResolver->resolve($property, ReportKey::HousekeepingTasks),
            'orientationOptions' => PageOrientation::options(),
            'canEditLayout' => $user->can(PermissionEnum::SettingsManage->value),
            'can' => [
                'update' => $canUpdate,
                'inspect' => $canInspect,
                'block' => $canBlock,
            ],
        ]);
    }

    public function status(UpdateRoomStatusRequest $request, Property $property, Room $room, UpdateRoomStatus $updateRoomStatus): RedirectResponse
    {
        abort_if($room->archived_at !== null, 404);

        $refusal = $updateRoomStatus($request->user(), $room, $request->status());

        Inertia::flash('toast', $refusal === null
            ? ['type' => 'success', 'message' => __('Room :room is now :status.', ['room' => $room->number, 'status' => $request->status()->label()])]
            : ['type' => 'error', 'message' => $refusal->message($room->number)]);

        return back();
    }

    public function assignee(AssignHousekeeperRequest $request, Property $property, Room $room, AssignHousekeeper $assignHousekeeper): RedirectResponse
    {
        abort_if($room->archived_at !== null, 404);

        $assignHousekeeper($request->user(), $room, $request->assignee());

        return to_route('housekeeping.index', $property);
    }

    public function priority(SetRoomPriorityRequest $request, Property $property, Room $room, SetRoomPriority $setRoomPriority): RedirectResponse
    {
        abort_if($room->archived_at !== null, 404);

        $setRoomPriority($request->user(), $room, $request->boolean('priority'), $request->note());

        return to_route('housekeeping.index', $property);
    }

    public function dnd(SetDoNotDisturbRequest $request, Property $property, Room $room, SetDoNotDisturb $setDoNotDisturb, HousekeepingDate $housekeepingDate): RedirectResponse
    {
        abort_if($room->archived_at !== null, 404);

        $setDoNotDisturb($request->user(), $room, $request->boolean('dnd'), $housekeepingDate->for($property));

        return back();
    }

    /**
     * Run one action on the ticked rooms. A room of another property or an archived room makes the whole request 404.
     */
    public function bulk(BulkUpdateRoomsRequest $request, Property $property, BulkUpdateRooms $bulkUpdateRooms): RedirectResponse
    {
        $ids = $request->roomIds();
        $rooms = $property->rooms()->active()->with('status')->whereIn('id', $ids)->ordered()->get();

        abort_if($rooms->count() !== count($ids), 404);

        $result = $bulkUpdateRooms($request->user(), $rooms, $request->action(), $request->assignee(), $request->remark());

        $message = trans_choice('{0} No rooms updated.|{1} :count room updated.|[2,*] :count rooms updated.', $result->done);

        if ($result->skipped !== []) {
            $reasons = array_map(fn (array $skip): string => $skip['refusal']->message($skip['number']), array_slice($result->skipped, 0, 3));
            $more = count($result->skipped) - count($reasons);

            $message .= ' '.__(':count skipped: :reasons', [
                'count' => count($result->skipped),
                'reasons' => implode(' ', $reasons).($more > 0 ? ' '.__('and :count more.', ['count' => $more]) : ''),
            ]);
        }

        Inertia::flash('toast', [
            'type' => $result->done === 0 ? 'error' : ($result->skipped === [] ? 'success' : 'info'),
            'message' => $message,
        ]);

        return to_route('housekeeping.index', $property);
    }

    public function remark(SetRoomRemarkRequest $request, Property $property, Room $room, SetRoomRemark $setRoomRemark): RedirectResponse
    {
        abort_if($room->archived_at !== null, 404);

        $setRoomRemark($request->user(), $room, $request->validated('remark'));

        return to_route('housekeeping.index', $property);
    }

    public function maintenance(SetNeedsMaintenanceRequest $request, Property $property, Room $room, SetNeedsMaintenance $setNeedsMaintenance): RedirectResponse
    {
        abort_if($room->archived_at !== null, 404);

        $setNeedsMaintenance($request->user(), $room, $request->boolean('needs_maintenance'));

        return to_route('housekeeping.index', $property);
    }

    /**
     * Active staff of the property who can update the board.
     *
     * @return list<HousekeepingStaffData>
     */
    private function staff(Property $property): array
    {
        $roleIds = Role::query()
            ->where('property_id', $property->id)
            ->whereHas('permissions', fn ($query) => $query->where('name', PermissionEnum::HousekeepingUpdate->value))
            ->pluck('id');

        return array_values(User::query()
            ->where('property_id', $property->id)
            ->whereNull('deactivated_at')
            ->whereHas('roles', fn ($query) => $query->whereIn('roles.id', $roleIds))
            ->orderBy('name')
            ->get()
            ->map(fn (User $staff): HousekeepingStaffData => HousekeepingStaffData::fromModel($staff))
            ->all());
    }
}
