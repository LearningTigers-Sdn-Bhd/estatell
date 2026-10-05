<?php

namespace App\Actions\Registration;

use App\Enums\Locale;
use App\Models\Property;
use App\Models\TermsDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTermsDocument
{
    public function __construct(private ApplyTermsPreset $applyTermsPreset) {}

    /**
     * Add a terms document. It starts as a copy of the template for the property type in every language.
     */
    public function __invoke(Property $property, User $actor, string $name): TermsDocument
    {
        return DB::transaction(function () use ($property, $actor, $name): TermsDocument {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $document = $property->termsDocuments()->create(['name' => $name, 'is_default' => false]);

            foreach (Locale::cases() as $locale) {
                ($this->applyTermsPreset)($document, $locale, null);
            }

            activity()
                ->performedOn($document)
                ->causedBy($actor)
                ->event('terms_document.created')
                ->withProperties(['property_id' => $property->id, 'name' => $name])
                ->log('Terms document created');

            return $document;
        });
    }
}
