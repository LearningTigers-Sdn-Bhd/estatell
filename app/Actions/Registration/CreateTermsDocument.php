<?php

namespace App\Actions\Registration;

use App\Enums\Locale;
use App\Enums\TermsPreset;
use App\Models\Property;
use App\Models\TermsDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTermsDocument
{
    public function __construct(private ApplyTermsPreset $applyTermsPreset) {}

    /**
     * Add a terms document. It starts as a copy of a template in every language. Without a preset, the one for the property type is used.
     */
    public function __invoke(Property $property, User $actor, string $name, ?TermsPreset $preset = null): TermsDocument
    {
        return DB::transaction(function () use ($property, $actor, $name, $preset): TermsDocument {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $document = $property->termsDocuments()->create(['name' => $name, 'is_default' => false]);

            foreach (Locale::cases() as $locale) {
                ($this->applyTermsPreset)($document, $locale, null, $preset);
            }

            activity()
                ->performedOn($document)
                ->causedBy($actor)
                ->event('terms_document.created')
                ->withProperties(['property_id' => $property->id, 'name' => $name, 'preset' => $preset?->value])
                ->log('Terms document created');

            return $document;
        });
    }
}
