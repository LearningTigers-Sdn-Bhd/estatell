<?php

namespace App\Actions\Folio;

use App\Models\Folio;
use App\Models\FolioManualBatch;
use App\Models\User;

class CreateManualBatch
{
    /**
     * Write the batch row that gives a manual action its reason. The caller runs it inside its transaction, after its
     * checks, and passes the form's UUID as `$key`. See docs/13-folio/08-manual-folio.md.
     */
    public function __invoke(User $actor, Folio $folio, string $reason, string $key): FolioManualBatch
    {
        $batch = new FolioManualBatch(['reason' => trim($reason), 'idempotency_key' => $key]);
        $batch->property_id = $folio->property_id;
        $batch->folio_id = $folio->id;
        $batch->user_id = $actor->id;
        $batch->save();

        return $batch;
    }
}
