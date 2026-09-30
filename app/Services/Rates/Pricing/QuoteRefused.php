<?php

namespace App\Services\Rates\Pricing;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Raised inside a strategy when a night cannot be priced. `RateQuoteService` turns it into a refused quote.
 */
class QuoteRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?CarbonInterface $date = null)
    {
        parent::__construct($reason);
    }
}
