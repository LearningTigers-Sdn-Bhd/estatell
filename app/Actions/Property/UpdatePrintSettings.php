<?php

namespace App\Actions\Property;

use App\Models\Property;
use App\Models\User;

class UpdatePrintSettings
{
    /**
     * Save the font, the font size and the footer text of the property's PDFs.
     *
     * @param  array<string, mixed>  $attributes  Validated font, font_size and footer_text.
     */
    public function __invoke(User $actor, Property $property, array $attributes): void
    {
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
