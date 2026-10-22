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
 * The replay importer passes a planned number to keep a number issued elsewhere. The sequence issues exactly that
 * number when it is above the last number, and refuses it otherwise.
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
     * @param  int|null  $planned  An exact number to issue. It must be above `last_number` and `floor`, or `PlannedNumberRefused` is thrown and nothing is written.
     *
     * @throws PlannedNumberRefused
     */
    public function __invoke(Property $property, DocumentType $type, ?int $floor = null, ?int $planned = null): IssuedDocumentNumber
    {
        $year = ($this->currentBusinessDate->date($property) ?? $this->propertyToday->date($property))->year;
        $prefix = ($this->generateDocumentPrefix)($property);

        $number = DB::transaction(function () use ($property, $type, $year, $floor, $planned): int {
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

            $highest = max($sequence->last_number, $floor ?? 0);

            if ($planned !== null && $planned <= $highest) {
                throw new PlannedNumberRefused($planned, $highest);
            }

            $sequence->last_number = $planned ?? $highest + 1;
            $sequence->save();

            return $sequence->last_number;
        });

        return new IssuedDocumentNumber($number, $year, $this->documentReference->format($prefix, $year, $type, $number));
    }
}
