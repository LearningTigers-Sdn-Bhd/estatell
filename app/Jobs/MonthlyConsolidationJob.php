<?php

namespace App\Jobs;

use App\Actions\EInvoice\ConsolidateMonthlyEInvoices;
use App\Features\EInvoicing;
use App\Models\Property;
use App\Services\EInvoice\MissingEInvoiceDetails;
use App\Services\EInvoice\SummaryMonth;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;
use Throwable;

class MonthlyConsolidationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $propertyId = null,
        public ?string $targetMonth = null
    ) {}

    public function handle(ConsolidateMonthlyEInvoices $consolidateMonthlyEInvoices, SummaryMonth $summaryMonth): void
    {
        $query = Property::query()->with(['eInvoiceSetting', 'settings'])->whereHas('eInvoiceSetting', function ($q) {
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

            // One hotel's gap or error must not stop the hotels after it. Nothing of that hotel was saved or sent.
            try {
                // The month before the current one in the hotel's own time zone, unless a month was given.
                $month = $this->targetMonth !== null ? $summaryMonth->parse($property, CarbonImmutable::parse($this->targetMonth)->format('Y-m')) : $summaryMonth->previous($property);
                $consolidateMonthlyEInvoices($property, $month);
            } catch (MissingEInvoiceDetails $exception) {
                Log::warning("Monthly e-invoice summary not sent for property {$property->id}: {$exception->getMessage()}");
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
