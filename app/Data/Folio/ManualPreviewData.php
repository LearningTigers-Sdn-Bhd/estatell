<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * What the manual folio page shows for its draft rows, all from the server: one answer per row, then the summary rail
 * for the folio being managed (charges, payments and balance, now and after) and the balance of every folio the rows
 * touch. `closeBlocked` is the message of the first reason the folio cannot close (null when it can). Refused rows add
 * nothing to the figures. It writes nothing.
 */
class ManualPreviewData extends Data
{
    /**
     * @param  list<ManualRowPreviewData>  $rows
     * @param  list<ManualRailFolioData>  $folios
     */
    public function __construct(
        public array $rows,
        public string $chargesNow,
        public string $chargesAfter,
        public string $paymentsNow,
        public string $paymentsAfter,
        public string $balanceNow,
        public string $balanceAfter,
        public array $folios,
        public ?string $closeBlocked,
    ) {}
}
