<?php

namespace App\Jobs;

use App\Actions\EInvoice\SubmitFolioEInvoice;
use App\Models\Folio;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Send a closed folio's e-invoice by itself, after check-out (`QueueFolioEInvoiceOnClose`). It runs once: a send whose
 * answer was lost is looked up by `RefreshEInvoiceStatusJob`, never sent again from here. A refusal (such as a missing
 * city) is logged and the stay waits for staff or the monthly summary.
 */
class SubmitFolioEInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $folioId, public ?int $actorId = null) {}

    public function handle(SubmitFolioEInvoice $submitFolioEInvoice): void
    {
        $folio = Folio::query()->with('property.eInvoiceSetting')->find($this->folioId);

        if ($folio === null) {
            return;
        }

        $actor = $this->actorId === null ? null : User::query()->find($this->actorId);
        $result = $submitFolioEInvoice($folio, $actor);

        if (! $result->ok) {
            Log::info("E-invoice not sent on its own for folio {$folio->id}: {$result->message}");
        }
    }
}
