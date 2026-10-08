<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilters;
use App\Enums\TransactionKind;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\UnitAddOn;
use App\Models\UnitNight;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * The tourism tax of each reservation unit in a date range: the Tax lines of the property's foreign-guests-only taxes
 * with a posting date in the range, reversals included (they are negative). A tax is found by the transaction code of
 * its lines (one code for each tax).
 *
 * A tax line has no source of its own. It takes the source of its parent charge, and a tax on a discount takes the
 * source of the charge above the discount, so the query follows `parent_line_id` up to two levels, like
 * `FolioStatement`. The source names a reservation unit directly, or a unit night or an add-on that belongs to one.
 * All of it is one grouped query.
 */
class TourismTaxByUnit
{
    /**
     * @param  list<int>  $reservationIds  The reservations of the units asked for; the folios of other reservations are not read.
     * @return array<int, string> The amount by unit id, with two decimals; a unit with no line has no entry.
     */
    public function forRange(Property $property, ReportFilters $filters, array $reservationIds): array
    {
        $codeIds = Tax::query()
            ->where('property_id', $property->id)
            ->where('foreign_guests_only', true)
            ->pluck('transaction_code_id')
            ->all();

        if ($codeIds === [] || $reservationIds === []) {
            return [];
        }

        $type = 'coalesce(tax.source_type, parent.source_type, grand.source_type)';
        $id = 'coalesce(tax.source_id, parent.source_id, grand.source_id)';

        $totals = DB::table('folio_lines as tax')
            ->join('folios', 'folios.id', '=', 'tax.folio_id')
            ->leftJoin('folio_lines as parent', 'parent.id', '=', 'tax.parent_line_id')
            ->leftJoin('folio_lines as grand', 'grand.id', '=', 'parent.parent_line_id')
            ->leftJoin('unit_nights', fn ($join) => $join->whereRaw("unit_nights.id = {$id}")->whereRaw("{$type} = ?", [(new UnitNight)->getMorphClass()]))
            ->leftJoin('unit_add_ons', fn ($join) => $join->whereRaw("unit_add_ons.id = {$id}")->whereRaw("{$type} = ?", [(new UnitAddOn)->getMorphClass()]))
            ->where('folios.property_id', $property->id)
            ->whereIn('folios.reservation_id', $reservationIds)
            ->where('tax.kind', TransactionKind::Tax->value)
            ->whereIn('tax.transaction_code_id', $codeIds)
            ->whereBetween('tax.posting_date', [$filters->from, $filters->to])
            ->selectRaw("coalesce(case when {$type} = ? then {$id} end, unit_nights.reservation_unit_id, unit_add_ons.reservation_unit_id) as unit_id, sum(tax.amount) as total", [(new ReservationUnit)->getMorphClass()])
            ->groupByRaw('1')
            ->get();

        $amounts = [];

        foreach ($totals as $row) {
            if ($row->unit_id !== null) {
                $amounts[(int) $row->unit_id] = (string) BigDecimal::of($row->total)->toScale(2, RoundingMode::HalfUp);
            }
        }

        return $amounts;
    }
}
