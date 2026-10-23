<?php

namespace App\Jobs;

use App\Actions\Accounting\ReconcileCompanyBilling;
use App\Models\Property;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileCompanyBillingJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * An expected failure is saved on the document, so only an unexpected error or a busy property is retried.
     */
    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public function __construct(public int $propertyId) {}

    /**
     * One waiting job per property is enough, because a run reads every change. Once it starts, a new change queues another.
     */
    public function uniqueId(): string
    {
        return (string) $this->propertyId;
    }

    public function handle(ReconcileCompanyBilling $reconcileCompanyBilling): void
    {
        $property = Property::query()->find($this->propertyId);

        if ($property !== null) {
            $reconcileCompanyBilling($property);
        }
    }
}
