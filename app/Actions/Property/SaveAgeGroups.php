<?php

namespace App\Actions\Property;

use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveAgeGroups
{
    /**
     * Replace the property's age groups with the ordered list.
     *
     * The first group starts at age 0 and each next group starts one year after the previous group ends,
     * so ranges never have gaps or overlaps. A row with an id keeps that id. A row without one is new.
     * An existing group missing from the list is deleted.
     *
     * Returns false, without writing, when a row id is not one of the property's current groups.
     * A save that changes nothing is not logged.
     *
     * @param  list<array{id?: int|null, label: string, max_age: int}>  $groups
     */
    public function __invoke(Property $property, User $actor, array $groups): bool
    {
        return DB::transaction(function () use ($property, $actor, $groups): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $existing = $property->ageGroups()->get()->keyBy('id');
            $keptIds = array_values(array_filter(array_column($groups, 'id')));

            if (array_diff($keptIds, $existing->keys()->all()) !== []) {
                return false;
            }

            $wanted = [];
            $minAge = 0;

            foreach ($groups as $group) {
                $wanted[] = ['id' => $group['id'] ?? null, 'label' => $group['label'], 'min_age' => $minAge, 'max_age' => $group['max_age']];
                $minAge = $group['max_age'] + 1;
            }

            $before = $existing->sortBy('min_age')->map(fn (PropertyAgeGroup $group): array => [
                'label' => $group->label, 'min_age' => $group->min_age, 'max_age' => $group->max_age,
            ])->values()->all();
            $after = array_map(fn (array $row): array => [
                'label' => $row['label'], 'min_age' => $row['min_age'], 'max_age' => $row['max_age'],
            ], $wanted);

            if ($before === $after && count($keptIds) === count($wanted)) {
                return true;
            }

            $property->ageGroups()->whereNotIn('id', $keptIds)->delete();

            // Free every label that changes first, so labels can move between rows in one save.
            foreach ($wanted as $row) {
                if ($row['id'] !== null && $existing[$row['id']]->label !== $row['label']) {
                    $property->ageGroups()->whereKey($row['id'])->update(['label' => 'moving-'.$row['id']]);
                }
            }

            foreach ($wanted as $row) {
                $attributes = ['label' => $row['label'], 'min_age' => $row['min_age'], 'max_age' => $row['max_age']];

                if ($row['id'] === null) {
                    $property->ageGroups()->create($attributes);
                } else {
                    $property->ageGroups()->whereKey($row['id'])->update($attributes);
                }
            }

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('age_groups.updated')
                ->withProperties(['property_id' => $property->id, 'before' => $before, 'after' => $after])
                ->log('Age groups updated');

            return true;
        });
    }
}
