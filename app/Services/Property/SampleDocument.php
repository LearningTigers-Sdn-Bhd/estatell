<?php

namespace App\Services\Property;

use App\Enums\TransactionKind;
use App\Models\PrintSetting;
use App\Services\Folio\DocumentLineGroups;
use App\Services\Folio\DocumentLines;
use App\Services\Folio\DocumentRow;
use Carbon\CarbonImmutable;

/**
 * The sample lines of the sample PDF on the Documents tab. They go through the same line grouper as the real
 * documents, so the switches change the sample the way they change a real booking summary.
 */
class SampleDocument
{
    public function __construct(private DocumentLines $documentLines) {}

    public function groups(PrintSetting $settings): DocumentLineGroups
    {
        $start = CarbonImmutable::parse('2026-10-12');
        $rows = [];

        foreach ([[1, 'Room 103 · Tan Wei Ming', '250.00'], [2, 'Room 104 · Lee Mei Ling', '230.00']] as [$unitId, $label, $rate]) {
            foreach (range(0, 2) as $night) {
                $rows[] = new DocumentRow(TransactionKind::Charge, 'Room', $start->addDays($night), 1, $rate, $rate, $unitId, $label, nightly: true);
            }
        }

        $rows[] = new DocumentRow(TransactionKind::Charge, 'Island hopping trip', $start->addDay(), 1, '360.00', '360.00', 1, 'Room 103 · Tan Wei Ming');
        $rows[] = new DocumentRow(TransactionKind::Tax, 'Service tax 8%', $start, 1, '144.00', '144.00');
        $rows[] = new DocumentRow(TransactionKind::Tax, 'Tourism tax', $start, 1, '60.00', '60.00');

        return $this->documentLines->group($rows, $settings);
    }
}
