<?php

namespace App\Actions\Property;

use App\Models\Property;
use App\Models\User;

class UpdatePrintDocuments
{
    /**
     * Save the document switches and the payment details text. The text is trimmed and an empty one is stored as null.
     *
     * @param  array<string, mixed>  $attributes  Validated switches and bank_details.
     */
    public function __invoke(User $actor, Property $property, array $attributes): void
    {
        $bankDetails = array_key_exists('bank_details', $attributes) ? trim((string) $attributes['bank_details']) : null;
        $attributes['bank_details'] = $bankDetails === '' ? null : $bankDetails;

        $printSetting = $property->printSetting;
        $printSetting->fill($attributes);
        $changes = $printSetting->getDirty();

        if ($changes === []) {
            return;
        }

        $printSetting->save();

        activity()
            ->performedOn($printSetting)
            ->causedBy($actor)
            ->event('print_settings.updated')
            ->withProperties(['property_id' => $property->id, 'changed' => array_keys($changes)])
            ->log('Print settings updated');
    }
}
