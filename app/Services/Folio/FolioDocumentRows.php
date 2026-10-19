<?php

namespace App\Services\Folio;

use App\Enums\ChargingUnit;
use App\Enums\SellUnit;
use App\Enums\TransactionKind;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\UnitAddOn;
use App\Models\UnitNight;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use App\Services\Reservations\ReservationRoomBox;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Turns posted folio lines into the grouper's `DocumentRow`s, one way for every customer document: the unit through
 * `LineSourceUnits`, a child line (discount, tax) taking its parent's unit and nightly flag, the nightly rule, the
 * room label, the group heading and, on a tax row, the tax registration number. It also names each unit's place. The
 * booking summary, the folio invoice and the AR invoice use it. It only reads.
 * See docs/02-property/05-printing-settings.md#line-grouper.
 */
class FolioDocumentRows
{
    public function __construct(
        private LineSourceUnits $lineSourceUnits,
        private ReservationRoomBox $roomBox,
        private CurrentBusinessDate $currentBusinessDate,
        private PropertyToday $propertyToday,
    ) {}

    /**
     * Each unit's place ("Room 103", or null while none is assigned), its Room column label and its group heading.
     * The room is tonight's, else the first night's.
     *
     * @param  EloquentCollection<int, ReservationUnit>  $units  With `roomType` and `guests.guest` loaded
     * @return array<int, array{place: ?string, room: string, heading: string}> By unit id
     */
    public function places(Property $property, Collection $units): array
    {
        $ids = array_values(array_map(intval(...), $units->modelKeys()));
        $tonight = $this->roomBox->assignmentsForUnitsOn($ids, ($this->currentBusinessDate->date($property) ?? $this->propertyToday->date($property))->toDateString());
        $first = $this->roomBox->assignmentsForUnits($ids);
        $places = [];

        foreach ($units as $unit) {
            $assigned = $tonight[$unit->id] ?? $first[$unit->id] ?? null;
            $place = $assigned === null ? null : ($unit->roomType->sell_unit === SellUnit::Bed ? __('Bed :label', ['label' => $assigned]) : __('Room :number', ['number' => $assigned]));
            $suffix = $unit->status->holdsInventory() ? '' : ' ('.$unit->status->label().')';
            $primary = $unit->guests->firstWhere('is_primary', true)?->guest?->full_name;

            $places[$unit->id] = [
                'place' => $place,
                'room' => $assigned ?? $unit->roomType->name,
                'heading' => implode(' · ', array_filter([($place ?? $unit->roomType->name).$suffix, $primary], filled(...))),
            ];
        }

        return $places;
    }

    /**
     * The rows of the posted lines, payments left out, sorted by the order of the units, then the service date, then
     * the order the lines come in.
     *
     * @param  Collection<int, FolioLine>  $lines  Posted lines in ledger order
     * @param  EloquentCollection<int, ReservationUnit>  $units  The units of the reservation, in print order
     * @param  array<int, array{place: ?string, room: string, heading: string}>  $places  See `places()`
     * @param  bool  $withTaxNumbers  False leaves the tax registration numbers off, for a document that prints none
     * @return list<DocumentRow>
     */
    public function for(Property $property, Collection $lines, Collection $units, array $places, bool $withTaxNumbers = true): array
    {
        $byId = $lines->keyBy('id');
        $sourceUnits = $this->lineSourceUnits->for($lines);
        $addOns = UnitAddOn::query()->whereIn('reservation_unit_id', $units->modelKeys())->with('chargeItem')->get()->keyBy('id');
        $nightMorph = (new UnitNight)->getMorphClass();
        $addOnMorph = (new UnitAddOn)->getMorphClass();
        $order = array_flip($units->modelKeys());
        $taxNumbers = $withTaxNumbers ? $this->taxNumbers($property, $lines) : [];

        $unitOf = function (FolioLine $line) use (&$unitOf, $byId, $sourceUnits): ?int {
            $own = $line->source_id === null ? null : ($sourceUnits[$line->source_type.':'.$line->source_id] ?? null);

            if ($own !== null || $line->parent_line_id === null || ! $byId->has($line->parent_line_id)) {
                return $own;
            }

            return $unitOf($byId[$line->parent_line_id]);
        };
        $nightlyOf = function (FolioLine $line) use (&$nightlyOf, $byId, $addOns, $nightMorph, $addOnMorph): bool {
            if ($line->parent_line_id !== null && $byId->has($line->parent_line_id)) {
                return $nightlyOf($byId[$line->parent_line_id]);
            }

            return match ($line->source_type) {
                $nightMorph => true,
                $addOnMorph => self::isNightlyAddOn($addOns->get($line->source_id)),
                default => false,
            };
        };

        $entries = [];

        foreach ($lines as $line) {
            if ($line->kind === TransactionKind::Payment) {
                continue;
            }

            $unitId = $unitOf($line);
            $entries[] = [
                'sort' => [$order[$unitId] ?? PHP_INT_MAX, $line->service_date->toDateString(), count($entries)],
                'row' => new DocumentRow(
                    $line->kind,
                    $line->description,
                    CarbonImmutable::parse($line->service_date->toDateString()),
                    $line->quantity,
                    $line->quantity === 1 ? $line->amount : $line->unit_amount,
                    $line->amount,
                    $unitId,
                    $unitId === null ? null : ($places[$unitId]['heading'] ?? null),
                    $line->id,
                    $line->reversal_of_id,
                    $nightlyOf($line),
                    $unitId === null ? null : ($places[$unitId]['room'] ?? null),
                    $line->kind === TransactionKind::Tax ? ($taxNumbers[$line->transaction_code_id] ?? null) : null,
                ),
            ];
        }

        usort($entries, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_map(fn (array $entry): DocumentRow => $entry['row'], $entries);
    }

    /**
     * The Rooms block of a document that has no Rooms section: one line per unit, "Room 103 · Tan Wei Ming · 12–15 Oct".
     * The place is the room, else the room type name; the guest is the unit's primary guest. Both are left out when empty.
     *
     * @param  EloquentCollection<int, ReservationUnit>  $units  With `roomType` and `guests.guest` loaded
     * @param  array<int, array{place: ?string, room: string, heading: string}>  $places  See `places()`
     * @return list<string>
     */
    public function roomLines(Collection $units, array $places): array
    {
        return array_values($units->map(fn (ReservationUnit $unit): string => implode(' · ', array_filter([
            $places[$unit->id]['place'] ?? $unit->roomType->name,
            $unit->guests->firstWhere('is_primary', true)?->guest?->full_name,
            DateRange::short(CarbonImmutable::parse($unit->arrival_date->toDateString()), CarbonImmutable::parse($unit->departure_date->toDateString())),
        ], filled(...))))->all());
    }

    /**
     * The units that have a charge on the folio (a line that is reversed on it does not count), and the unit of a Unit folio.
     *
     * @param  list<DocumentRow>  $rows
     * @param  EloquentCollection<int, ReservationUnit>  $units
     * @return EloquentCollection<int, ReservationUnit>
     */
    public function unitsOnFolio(Folio $folio, array $rows, Collection $units): Collection
    {
        $reversed = array_filter(array_map(fn (DocumentRow $row): ?int => $row->reversalOfId, $rows));
        $ids = [$folio->reservation_unit_id];

        foreach ($rows as $row) {
            if ($row->reversalOfId === null && ! in_array($row->lineId, $reversed, true)) {
                $ids[] = $row->unitId;
            }
        }

        $ids = array_filter($ids);

        return $units->filter(fn (ReservationUnit $unit): bool => in_array($unit->id, $ids, true))->values();
    }

    /**
     * An add-on charged PerNight or PerGuestNight is nightly.
     */
    public static function isNightlyAddOn(?UnitAddOn $addOn): bool
    {
        return in_array($addOn?->chargeItem->charging_unit, [ChargingUnit::PerNight, ChargingUnit::PerGuestNight], true);
    }

    /**
     * The tax number each tax code prints, as the letterhead prints a tax number ("SST W10-1808-32000123"). A tax with no
     * registration number has none.
     *
     * @param  Collection<int, FolioLine>  $lines
     * @return array<int, string> By transaction code id
     */
    private function taxNumbers(Property $property, Collection $lines): array
    {
        $codes = $lines->where('kind', TransactionKind::Tax)->pluck('transaction_code_id')->unique()->all();

        if ($codes === []) {
            return [];
        }

        return Tax::query()
            ->where('property_id', $property->id)
            ->whereIn('transaction_code_id', $codes)
            ->whereNotNull('registration_number')
            ->get()
            ->filter(fn (Tax $tax): bool => filled($tax->registration_number))
            ->mapWithKeys(fn (Tax $tax): array => [$tax->transaction_code_id => $tax->code.' '.$tax->registration_number])
            ->all();
    }
}
