<?php

namespace App\Actions\Onboarding\Wastays;

use App\Models\DocumentSequence;
use App\Models\Property;
use App\Models\PropertyDocumentPrefixHistory;
use RuntimeException;

/**
 * Gives the property the wastays hotel code as its document prefix, so the imported document numbers stay 1:1. A
 * prefix that already matches is left alone. A property that has issued a document, or a prefix that another property
 * holds or held, is refused and nothing is written.
 */
class AlignWastaysDocumentPrefix
{
    /**
     * @return string|null "OLD → NEW" when the prefix changed, or null when it already matched
     *
     * @throws RuntimeException with a sentence for the console when the prefix cannot change
     */
    public function __invoke(Property $property, string $hotelCode): ?string
    {
        if ($property->document_prefix === $hotelCode) {
            return null;
        }

        if (DocumentSequence::query()->where('property_id', $property->id)->exists()) {
            throw new RuntimeException("The property has already issued documents with the prefix {$property->document_prefix}, so it cannot take {$hotelCode}.");
        }

        $takenByAnother = Property::query()->where('document_prefix', $hotelCode)->whereKeyNot($property->id)->exists()
            || PropertyDocumentPrefixHistory::query()->where('prefix', $hotelCode)->where('property_id', '!=', $property->id)->exists();

        if ($takenByAnother) {
            throw new RuntimeException("Another property holds or held the prefix {$hotelCode}.");
        }

        $previous = $property->document_prefix ?? 'not set';
        $property->document_prefix = $hotelCode;
        $property->save();
        PropertyDocumentPrefixHistory::query()->firstOrCreate(['property_id' => $property->id, 'prefix' => $hotelCode]);

        return "{$previous} → {$hotelCode}";
    }
}
