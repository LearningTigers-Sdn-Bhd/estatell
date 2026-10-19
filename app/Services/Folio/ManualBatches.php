<?php

namespace App\Services\Folio;

use App\Actions\Folio\PostingResult;
use App\Models\Folio;
use App\Models\FolioManualBatch;
use InvalidArgumentException;

/**
 * Finds the batch of an earlier manual action when its form is sent again. It writes nothing.
 */
class ManualBatches
{
    /**
     * The earlier result for this form key, with the lines the batch wrote, or null when the key is new. A key that
     * belongs to another property is a programming error.
     */
    public function replay(Folio $folio, string $key): ?PostingResult
    {
        $batch = FolioManualBatch::query()->where('idempotency_key', $key)->first();

        if ($batch === null) {
            return null;
        }

        if ($batch->property_id !== $folio->property_id) {
            throw new InvalidArgumentException('The batch key belongs to another property.');
        }

        return PostingResult::replayed(array_values($batch->lines()->orderBy('id')->get()->all()));
    }
}
