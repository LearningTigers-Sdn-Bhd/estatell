<?php

namespace App\Jobs;

use App\Actions\EInvoice\ConsolidateMonthlyEInvoices;
use App\Features\EInvoicing;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\Pennant\Feature;

class MonthlyConsolidationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $propertyId = null,
        public ?string $targetMonth = null
    ) {}

    public function handle(ConsolidateMonthlyEInvoices $consolidateMonthlyEInvoices): void
    {
        $targetMonthDate = $this->targetMonth !== null
            ? CarbonImmutable::parse($this->targetMonth)
            : CarbonImmutable::now('Asia/Kuala_Lumpur')->subMonth();

        $query = Property::query()->whereHas('eInvoiceSetting', function ($q) {
            $q->where('enabled', true)
                ->where('consolidation_enabled', true);
        });

        if ($this->propertyId !== null) {
            $query->where('id', $this->propertyId);
        }

        $properties = $query->get();

        foreach ($properties as $property) {
            if (! Feature::for($property)->active(EInvoicing::class)) {
                continue;
            }

            $consolidateMonthlyEInvoices($property, $targetMonthDate);
        }
    }
}
