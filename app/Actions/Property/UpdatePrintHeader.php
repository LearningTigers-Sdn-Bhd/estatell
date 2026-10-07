<?php

namespace App\Actions\Property;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdatePrintHeader
{
    /**
     * Save the letterhead switches and the taxes whose numbers it shows, in one transaction.
     *
     * Returns false without writing when a tax belongs to another property.
     *
     * @param  array{show_logo: bool, show_address: bool, show_contact: bool, show_registration_number: bool, show_tin: bool}  $switches
     * @param  list<int>  $taxIds
     */
    public function __invoke(User $actor, Property $property, array $switches, array $taxIds): bool
    {
        $taxIds = array_values(array_unique($taxIds));

        if ($property->taxes()->whereKey($taxIds)->count() !== count($taxIds)) {
            return false;
        }

        DB::transaction(function () use ($actor, $property, $switches, $taxIds): void {
            $printSetting = $property->printSetting()->lockForUpdate()->firstOrFail();
            $printSetting->fill($switches);
            $changed = array_keys($printSetting->getDirty());
            $printSetting->save();

            $synced = $printSetting->taxes()->sync($taxIds);

            if ($synced['attached'] !== [] || $synced['detached'] !== []) {
                $changed[] = 'taxes';
            }

            if ($changed === []) {
                return;
            }

            activity()
                ->performedOn($printSetting)
                ->causedBy($actor)
                ->event('print_settings.updated')
                ->withProperties(['property_id' => $property->id, 'changed' => $changed])
                ->log('Print settings updated');
        });

        return true;
    }
}
