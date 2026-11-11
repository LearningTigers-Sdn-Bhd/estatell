<?php

namespace App\Services\GuestRequests;

use App\Data\GuestRequests\GuestRequestRowData;
use App\Enums\GuestRequestStatus;
use App\Enums\GuestRequestType;
use App\Enums\PermissionEnum;
use App\Enums\UnitStatus;
use App\Models\GuestRequest;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\UnitNight;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What the staff list shows. Reads only. A page holds at most `LIMIT` rows, oldest first, because a small hotel has a few
 * dozen open requests; the filters narrow it.
 */
class GuestRequestListBuilder
{
    public const int LIMIT = 300;

    public function __construct(private GuestRequestDay $day) {}

    /**
     * @param  string  $status  `active` (Open and InProgress), `all`, or one status value.
     * @return Collection<int, GuestRequestRowData>
     */
    public function rows(Property $property, User $viewer, string $status, ?GuestRequestType $type): Collection
    {
        $one = GuestRequestStatus::tryFrom($status);
        $today = $this->day->for($property)->toDateString();

        return GuestRequest::query()
            ->where('property_id', $property->id)
            ->when($one !== null, fn ($query) => $query->where('status', $one))
            ->when($one === null && $status !== 'all', fn ($query) => $query->whereIn('status', [GuestRequestStatus::Open, GuestRequestStatus::InProgress]))
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->with(['room', 'assignee', 'stay.reservation.booker'])
            ->orderBy('opened_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (GuestRequest $request): GuestRequestRowData => GuestRequestRowData::fromModel($request, $this->moves($request, $viewer, $today)));
    }

    /**
     * Staff of the property who can work on requests, for the Assign list.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function assignees(Property $property): array
    {
        return User::query()->where('property_id', $property->id)->orderBy('name')->get()
            ->filter(fn (User $user): bool => $user->can(PermissionEnum::GuestRequestsResolve->value))
            ->map(fn (User $user): array => ['value' => (string) $user->id, 'label' => $user->name])
            ->values()
            ->all();
    }

    /**
     * The stays a request can be about: checked in now. Each is labelled with its room and its booker.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function stayOptions(Property $property): array
    {
        $stays = ReservationUnit::query()->where('property_id', $property->id)->where('status', UnitStatus::CheckedIn)->with('reservation.booker')->get();
        $today = $this->day->for($property)->toDateString();
        $roomIds = UnitNight::query()->whereIn('reservation_unit_id', $stays->pluck('id'))->whereNotNull('room_id')->where('date', '<=', $today)->orderBy('date')->pluck('room_id', 'reservation_unit_id');
        $numbers = Room::query()->where('property_id', $property->id)->whereIn('id', $roomIds->values())->pluck('number', 'id');

        return $stays->map(fn (ReservationUnit $stay): array => [
            'value' => (string) $stay->id,
            'label' => trim(($numbers[$roomIds[$stay->id] ?? 0] ?? '').' · '.$stay->reservation->booker->full_name, ' ·'),
        ])->sortBy('label', SORT_NATURAL)->values()->all();
    }

    /**
     * @return list<string>
     */
    private function moves(GuestRequest $request, User $viewer, string $today): array
    {
        $manage = $viewer->can(PermissionEnum::GuestRequestsManage->value);
        $resolve = $viewer->can(PermissionEnum::GuestRequestsResolve->value);

        return match ($request->status) {
            GuestRequestStatus::Open => array_values(array_filter([$resolve ? 'start' : null, $resolve ? 'resolve' : null, $manage ? 'cancel' : null])),
            GuestRequestStatus::InProgress => array_values(array_filter([$resolve ? 'resolve' : null, $manage ? 'cancel' : null])),
            GuestRequestStatus::Resolved => $manage && $request->resolved_business_date?->toDateString() === $today ? ['reopen'] : [],
            GuestRequestStatus::Cancelled => $manage ? ['reopen'] : [],
        };
    }
}
