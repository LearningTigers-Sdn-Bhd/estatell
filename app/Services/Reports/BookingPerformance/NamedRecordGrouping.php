<?php

namespace App\Services\Reports\BookingPerformance;

use App\Models\Property;
use Illuminate\Database\Eloquent\Model;

/**
 * A grouping by a record of the property that has a name: the id comes from one column of the unit row, and the names
 * are read with one query, scoped to the property.
 */
abstract class NamedRecordGrouping implements BookingPerformanceGrouping
{
    /** The column of the unit row that holds the record id. */
    abstract protected function column(): string;

    /** @return class-string<Model> */
    abstract protected function model(): string;

    public function idOf(object $unit): ?int
    {
        $id = $unit->{$this->column()};

        return $id === null ? null : (int) $id;
    }

    public function labels(Property $property, array $ids): array
    {
        $ids = array_values(array_filter($ids, is_int(...)));

        if ($ids === []) {
            return [];
        }

        return $this->model()::query()
            ->where('property_id', $property->id)
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->map(fn (string $name): GroupLabel => new GroupLabel($name))
            ->all();
    }
}
