<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilters;
use App\Data\Reports\ReportSection;
use App\Data\Reports\ReportTotals;
use App\Data\Reports\TransferManifestRow;
use App\Enums\PermissionEnum;
use App\Enums\Tone;
use App\Enums\TransferDirection;
use App\Models\Property;
use App\Models\UnitTransfer;
use App\Services\Guests\GuestParty;
use App\Services\Reservations\ReservationRoomBox;
use App\Services\Transfers\TransferRunSheet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/**
 * The road transfers of one date for the drivers: the run sheet's transfers (`TransferRunSheet::query()`), in pick-up
 * order, with one section per vehicle and driver and a last section for the transfers with neither set. Guest names
 * and phones need `guests.view`, as on the run sheet.
 * See docs/35-transfers/01-land-transfers.md#transfer-manifest.
 */
class TransferManifestReport implements HasNote, HasSections, HasTotals, Report
{
    public function __construct(
        private TransferRunSheet $runSheet,
        private ReservationRoomBox $roomBox,
        private GuestParty $guestParty,
    ) {}

    /**
     * @param  list<int|string>|null  $rowKeys  Transfer ids.
     * @return list<TransferManifestRow>
     */
    public function rows(Property $property, ReportFilters $filters, ?array $rowKeys = null): array
    {
        $transfers = $this->runSheet->query($property, CarbonImmutable::parse($filters->from))
            ->when($rowKeys !== null, fn ($query) => $query->whereIn('id', array_map(intval(...), $rowKeys)))
            ->with(['transferRoute', 'transferVehicle', 'transferDriver', 'reservationUnit.reservation.booker', 'reservationUnit.roomType', 'reservationUnit.guests.guest'])
            ->get();
        $unitIds = fn (TransferDirection $direction): array => array_values(array_map(intval(...), $transfers
            ->where('direction', $direction)
            ->pluck('reservation_unit_id')
            ->unique()
            ->all()));
        $rooms = [
            TransferDirection::In->value => $this->roomBox->assignmentsForUnits($unitIds(TransferDirection::In)),
            TransferDirection::Out->value => $this->roomBox->lastNightAssignmentsForUnits($unitIds(TransferDirection::Out)),
        ];
        $canViewGuest = Auth::user()?->can(PermissionEnum::GuestsView->value) ?? false;
        $date = CarbonImmutable::parse($filters->from);

        return array_values($transfers
            ->map(fn (UnitTransfer $transfer): TransferManifestRow => TransferManifestRow::fromModel(
                $transfer,
                $transfer->reservationUnit,
                $rooms[$transfer->direction->value][$transfer->reservation_unit_id] ?? null,
                $canViewGuest,
                $canViewGuest ? $this->guestParty->line([$transfer->reservationUnit], $date, $transfer->reservationUnit->reservation->booker) : null,
            ))
            ->all());
    }

    /**
     * One section for each vehicle and driver, in the order of their first pick-up, then one for the transfers with
     * neither set.
     *
     * @param  list<TransferManifestRow>  $rows
     * @return list<ReportSection>
     */
    public function sections(array $rows, ReportFilters $filters): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[$row->vehicle_id === null && $row->driver_id === null ? 'not-set' : "{$row->vehicle_id}-{$row->driver_id}"][] = $row;
        }

        if (isset($groups['not-set'])) {
            $notSet = $groups['not-set'];
            unset($groups['not-set']);
            $groups['not-set'] = $notSet;
        }

        return array_values(array_map(function (array $group): ReportSection {
            $first = $group[0];
            $guests = array_sum(array_map(fn (TransferManifestRow $row): int => $row->total, $group));
            $isNotSet = $first->vehicle_id === null && $first->driver_id === null;

            return new ReportSection(
                title: $isNotSet
                    ? __('Vehicle and driver not set')
                    : ($first->vehicle ?? __('Vehicle not set')).' · '.($first->driver ?? __('Driver not set')),
                rowKeys: array_map(fn (TransferManifestRow $row): int => $row->key, $group),
                subtotal: trans_choice(':count transfer|:count transfers', count($group)).' · '.__(':count pax', ['count' => $guests]),
                tone: $isNotSet ? Tone::Warning : null,
            );
        }, $groups));
    }

    /**
     * Whom the driver calls: the lodge's duty manager and emergency phones from the hotel information settings, or null
     * when neither is set. Printed under the title of the PDF.
     */
    public function note(Property $property, ReportFilters $filters): ?string
    {
        $settings = $property->loadMissing('settings')->settings;
        $phones = array_filter([
            $settings?->duty_manager_phone === null ? null : __('Duty manager :phone', ['phone' => $settings->duty_manager_phone]),
            $settings?->emergency_phone === null ? null : __('Emergency :phone', ['phone' => $settings->emergency_phone]),
        ]);

        return $phones === [] ? null : __('In an emergency, call: :phones', ['phones' => implode(' · ', $phones)]);
    }

    /**
     * The adults, children, total guests and bags of the rows.
     *
     * @param  list<TransferManifestRow>  $rows
     */
    public function totals(array $rows): ReportTotals
    {
        return new ReportTotals([
            'pickup' => null,
            'direction' => null,
            'route' => null,
            'place' => null,
            'unit' => null,
            'lead_guest' => null,
            'phone' => null,
            'party' => null,
            'adults' => array_sum(array_map(fn (TransferManifestRow $row): int => $row->adults, $rows)),
            'children' => array_sum(array_map(fn (TransferManifestRow $row): int => $row->children, $rows)),
            'total' => array_sum(array_map(fn (TransferManifestRow $row): int => $row->total, $rows)),
            'bags' => array_sum(array_map(fn (TransferManifestRow $row): int => $row->bags ?? 0, $rows)),
            'flight' => null,
            'mode' => null,
            'vehicle' => null,
            'driver' => null,
            'reservation' => null,
            'note' => null,
        ]);
    }
}
