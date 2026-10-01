<?php

namespace App\Services\NightAudit;

use App\Actions\Property\GenerateDocumentPrefix;
use App\Enums\DocumentType;
use App\Models\DocumentSequence;
use App\Models\Property;
use App\Services\Property\PropertyToday;
use Illuminate\Support\Facades\DB;

/**
 * Issues the next number of a document type for a property.
 *
 * The sequence row is locked, so two requests never get the same number. When the caller already has a
 * transaction, the lock lasts until it commits and a rolled-back document gives its number back.
 * The year is the business date year, so a posting made after midnight and before the audit gets the right year.
 */
class NextDocumentNumber
{
    public function __construct(
        private CurrentBusinessDate $currentBusinessDate,
        private PropertyToday $propertyToday,
        private DocumentReference $documentReference,
        private GenerateDocumentPrefix $generateDocumentPrefix,
    ) {}

    /**
     * @param  int|null  $floor  The highest number already stored for this type and year. The next number is always above it.
     */
    public function __invoke(Property $property, DocumentType $type, ?int $floor = null): IssuedDocumentNumber
    {
        $year = ($this->currentBusinessDate->date($property) ?? $this->propertyToday->date($property))->year;
        $prefix = ($this->generateDocumentPrefix)($property);

        $number = DB::transaction(function () use ($property, $type, $year, $floor): int {
            DocumentSequence::query()->insertOrIgnore([
                'property_id' => $property->id,
                'type' => $type->value,
                'year' => $year,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DocumentSequence::query()
                ->where('property_id', $property->id)
                ->where('type', $type->value)
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();

            $sequence->last_number = max($sequence->last_number, $floor ?? 0) + 1;
            $sequence->save();

            return $sequence->last_number;
        });

        return new IssuedDocumentNumber($number, $year, $this->documentReference->format($prefix, $year, $type, $number));
    }
}
