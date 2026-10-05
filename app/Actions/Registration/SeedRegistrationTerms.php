<?php

namespace App\Actions\Registration;

use App\Enums\Locale;
use App\Enums\PropertyType;
use App\Enums\TermsPreset;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

class SeedRegistrationTerms
{
    public function __construct(private ApplyTermsPreset $applyTermsPreset) {}

    /**
     * Give a property its default "Standard" terms document, version 1 in every language,
     * from the template for its type. A property that already has a default document, or no type, is left alone.
     * The type defaults to the property's own, for a property that is not created yet.
     */
    public function __invoke(Property $property, ?PropertyType $type = null): void
    {
        $type ??= $property->type;

        if ($type === null) {
            return;
        }

        $preset = TermsPreset::forPropertyType($type);

        DB::transaction(function () use ($property, $preset): void {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);

            if ($property->termsDocuments()->where('is_default', true)->exists()) {
                return;
            }

            $document = $property->termsDocuments()->create(['name' => 'Standard', 'is_default' => true]);

            foreach (Locale::cases() as $locale) {
                ($this->applyTermsPreset)($document, $locale, null, $preset);
            }
        });
    }
}
