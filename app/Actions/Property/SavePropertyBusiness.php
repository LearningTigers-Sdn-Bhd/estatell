<?php

namespace App\Actions\Property;

use App\Models\Property;
use App\Models\PropertyLicence;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SavePropertyBusiness
{
    /**
     * Save the registered name, registration number, TIN and licences. The licences list replaces the saved one: a
     * row with an id keeps that id, a row without one is new, and a saved licence missing from the list is deleted.
     * The list order is the print order.
     *
     * Returns false, without writing, when a row id is not one of the property's licences. A save that changes
     * nothing is not logged.
     *
     * @param  array{legal_name: string|null, registration_number: string|null, tin: string|null}  $attributes
     * @param  list<array{id: int|null, issuer: string, number: string, expires_on: string|null, show_on_documents: bool}>  $licences
     */
    public function __invoke(User $actor, Property $property, array $attributes, array $licences): bool
    {
        return DB::transaction(function () use ($actor, $property, $attributes, $licences): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $existing = $property->licences()->get()->keyBy('id');
            $keptIds = array_values(array_filter(array_column($licences, 'id')));

            if (array_diff($keptIds, $existing->keys()->all()) !== []) {
                return false;
            }

            $changed = array_keys($property->fill($attributes)->getDirty());

            $before = $existing->sortBy('position')->map($this->snapshot(...))->values()->all();
            $after = array_map(fn (array $row): array => [
                'issuer' => $row['issuer'],
                'number' => $row['number'],
                'expires_on' => $row['expires_on'],
                'show_on_documents' => $row['show_on_documents'],
            ], $licences);

            if ($before !== $after || count($keptIds) !== count($licences)) {
                $changed[] = 'licences';
            }

            if ($changed === []) {
                return true;
            }

            $property->save();
            $property->licences()->whereNotIn('id', $keptIds)->delete();

            foreach ($licences as $position => $row) {
                $values = [
                    'issuer' => $row['issuer'],
                    'number' => $row['number'],
                    'expires_on' => $row['expires_on'],
                    'show_on_documents' => $row['show_on_documents'],
                    'position' => $position,
                ];

                if ($row['id'] === null) {
                    $property->licences()->create($values);
                } else {
                    $property->licences()->whereKey($row['id'])->update($values);
                }
            }

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('property.updated')
                ->withProperties(['property_id' => $property->id, 'changed' => $changed])
                ->log('Property business details updated');

            return true;
        });
    }

    /**
     * @return array{issuer: string, number: string, expires_on: string|null, show_on_documents: bool}
     */
    private function snapshot(PropertyLicence $licence): array
    {
        return [
            'issuer' => $licence->issuer,
            'number' => $licence->number,
            'expires_on' => $licence->expires_on?->toDateString(),
            'show_on_documents' => $licence->show_on_documents,
        ];
    }
}
