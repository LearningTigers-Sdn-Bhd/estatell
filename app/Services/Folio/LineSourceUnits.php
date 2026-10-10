<?php

namespace App\Services\Folio;

use App\Models\FolioLine;
use App\Models\ReservationUnit;
use App\Models\UnitAddOn;
use App\Models\UnitNight;
use Illuminate\Support\Collection;

/**
 * Finds the reservation unit that caused each folio line, through the line source. The folio tab and the customer
 * documents use it to group lines by unit. It only reads. See docs/02-property/05-printing-settings.md#line-grouper.
 */
class LineSourceUnits
{
    /**
     * The unit of each source the lines name, as `{source_type}:{source_id}` to unit id: three lookups at most, whatever
     * the number of lines. A unit night and an add-on point to their unit, and a reservation unit is its own.
     *
     * @param  Collection<int, FolioLine>  $lines
     * @return array<string, int>
     */
    public function for(Collection $lines): array
    {
        $result = [];
        $bySource = $lines->whereNotNull('source_id')->groupBy('source_type');
        $unit = (new ReservationUnit)->getMorphClass();

        foreach ($bySource->get($unit, []) as $line) {
            $result[$unit.':'.$line->source_id] = $line->source_id;
        }

        foreach ([UnitNight::class, UnitAddOn::class] as $model) {
            $morph = (new $model)->getMorphClass();
            $ids = $bySource->get($morph, collect())->pluck('source_id')->unique()->all();

            if ($ids === []) {
                continue;
            }

            foreach ($model::query()->whereIn('id', $ids)->pluck('reservation_unit_id', 'id') as $id => $unitId) {
                $result[$morph.':'.$id] = $unitId;
            }
        }

        return $result;
    }
}
