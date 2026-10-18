<?php

namespace App\Services\Dashboard;

use App\Data\Dashboard\DashboardTodoData;
use App\Data\Dashboard\DashboardTodoRowData;
use App\Data\NightAudit\ReadinessActionData;
use App\Enums\AuditCheck;
use App\Enums\PermissionEnum;
use App\Features\Boats;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Boats\MissingBoatArrivals;
use App\Services\NightAudit\AuditReadiness;
use App\Services\NightAudit\ReadinessItem;
use App\Services\Reservations\DashboardStays;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;

class DashboardTodo
{
    public function __construct(
        private AuditReadiness $readiness,
        private DashboardStays $stays,
        private MissingBoatArrivals $missingBoats,
    ) {}

    public function forDate(Property $property, CarbonImmutable $date, User $user): DashboardTodoData
    {
        $items = collect($this->readiness->items($property));
        $unitIds = [];

        foreach ($items as $item) {
            if ($item->reservationUnitId !== null) {
                $unitIds[] = $item->reservationUnitId;
            }
        }

        $units = $this->stays->forUnits($property, $unitIds);
        $property->loadMissing('settings');
        $timeZone = $property->settings->time_zone ?? config('app.timezone');
        $clock = now($timeZone);
        $rows = [];

        foreach ($items->filter(fn (ReadinessItem $item): bool => in_array($item->check, [AuditCheck::ArrivalNotCheckedIn, AuditCheck::DepartureNotCheckedOut], true))
            ->groupBy(fn (ReadinessItem $item): string => $item->check->value.':'.$item->reservationId) as $key => $group) {
            $first = $group->first();
            $groupUnits = $group->map(fn (ReadinessItem $item): ReservationUnit => $units->get($item->reservationUnitId));
            $arrival = $first->check === AuditCheck::ArrivalNotCheckedIn;
            $overdue = ! $arrival && $groupUnits->contains(fn (ReservationUnit $unit): bool => $unit->departure_date->toDateString() < $date->toDateString()
                || CarbonImmutable::parse($unit->departure_date->toDateString().' '.($unit->departure_time ?: $property->settings->check_out_time ?? '12:00:00'), $timeZone)->lt($clock));
            $kind = $arrival ? 'arrival' : ($overdue ? 'overdue' : 'departure');
            $expected = $groupUnits->map(fn (ReservationUnit $unit): string => substr(($arrival ? $unit->arrival_time : $unit->departure_time) ?: ($arrival ? $property->settings?->check_in_time : $property->settings?->check_out_time) ?? '12:00:00', 0, 5))->sort()->first();
            $canAct = $user->can(($arrival ? PermissionEnum::FrontDeskCheckIn : PermissionEnum::FrontDeskCheckOut)->value);
            $action = ReadinessActionData::link(__('View booking'), route('reservations.show', ['property' => $property->code, 'reservation' => $first->reservationId], false));

            if ($canAct) {
                if (! $arrival && $group->count() > 1) {
                    $action = ReadinessActionData::link(__('Check out'), route('reservations.check-out', ['property' => $property->code, 'reservation' => $first->reservationId], false), 'log-out');
                } else {
                    $action = new ReadinessActionData(
                        kind: 'dialog', label: $arrival ? __('Check in') : __('Check out'),
                        url: route('dashboard.reservation', ['property' => $property->code, 'reservation' => $first->reservationId], false),
                        unitId: $group->count() === 1 ? $first->reservationUnitId : null,
                        dialog: $arrival ? ($group->count() > 1 ? 'check-in-all' : 'check-in') : 'check-out',
                        icon: $arrival ? 'log-in' : 'log-out',
                    );
                }
            }

            $rows[] = new DashboardTodoRowData(
                key: $key, kind: $kind,
                label: match ($kind) {
                    'arrival' => __('Arrival'), 'overdue' => __('Overdue'), default => __('Departure')
                },
                guest: $first->guest ?? $first->reference, reference: $first->reference,
                detail: $group->count() > 1 ? __(':count rooms', ['count' => $group->count()]) : $this->unitDetail($groupUnits->first()),
                time: $expected, action: $action,
            );
        }

        foreach ($items->filter(fn (ReadinessItem $item): bool => in_array($item->check, [AuditCheck::MasterNotBilled, AuditCheck::FolioOpenAfterCheckOut, AuditCheck::MoneyHeldOnClosedBooking], true)) as $item) {
            $rows[] = new DashboardTodoRowData(
                key: $item->check->value.':'.$item->reservationId.':'.($item->folioId ?? $item->reservationUnitId),
                kind: 'folio', label: __('Folio'), guest: $item->guest ?? $item->reference, reference: $item->reference,
                detail: $item->detail, time: null,
                action: ReadinessActionData::link(__('Review folio'), route('reservations.folio', ['property' => $property->code, 'reservation' => $item->reservationId, ...($item->folioId === null ? [] : ['folio' => $item->folioId])], false), 'receipt'),
            );
        }

        if (Feature::for($property)->active(Boats::class)) {
            foreach ($this->missingBoats->forDate($property, $date)->groupBy('reservation_id') as $reservationId => $group) {
                $unit = $group->first();
                $rows[] = new DashboardTodoRowData(
                    key: 'boat:'.$reservationId, kind: 'boat', label: __('No boat'),
                    guest: $unit->reservation->booker->full_name ?? $unit->reservation->reference,
                    reference: $unit->reservation->reference,
                    detail: $group->count() > 1 ? __(':count rooms', ['count' => $group->count()]) : $unit->roomType->name,
                    time: substr($unit->arrival_time, 0, 5),
                    action: ReadinessActionData::link(__('Set boats'), route('reservations.show', ['property' => $property->code, 'reservation' => $reservationId], false), 'ship'),
                    when: $unit->arrival_date->isSameDay($date) ? __('Today') : __('Tomorrow'),
                );
            }
        }

        $order = ['overdue' => 0, 'arrival' => 1, 'boat' => 2, 'departure' => 3, 'folio' => 4];
        usort($rows, fn (DashboardTodoRowData $a, DashboardTodoRowData $b): int => [$order[$a->kind], $a->time, $a->key] <=> [$order[$b->kind], $b->time, $b->key]);

        return new DashboardTodoData(array_slice($rows, 0, 10), count($rows), $date->toDateString());
    }

    private function unitDetail(ReservationUnit $unit): string
    {
        if ($unit->dayUse !== null) {
            return __('Day use :start–:end', ['start' => substr($unit->dayUse->starts_at, 0, 5), 'end' => substr($unit->dayUse->ends_at, 0, 5)]);
        }

        return $unit->roomType->name;
    }
}
