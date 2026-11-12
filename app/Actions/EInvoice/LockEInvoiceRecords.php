<?php

namespace App\Actions\EInvoice;

use App\Models\Property;
use App\Models\PropertyEInvoiceSetting;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Run `$work` in a transaction that holds the property's e-invoice settings row locked. Every Action that decides
 * whether a folio, an AR invoice or an e-invoice may take a new e-invoice document, and saves that Pending document,
 * runs under this one lock, so two of them cannot both pass their "already has one" checks at the same time: a double
 * click, two staff, or a folio send against the monthly summary. The call to LHDN runs after it commits.
 */
class LockEInvoiceRecords
{
    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    public function __invoke(Property $property, Closure $work): mixed
    {
        return DB::transaction(function () use ($property, $work): mixed {
            PropertyEInvoiceSetting::query()->where('property_id', $property->id)->lockForUpdate()->first();

            return $work();
        });
    }
}
