<?php

namespace App\Actions\Folio;

use RuntimeException;

/**
 * Stops `WaiveGroupTax` when the waiver of one unit refuses, so its transaction rolls back. The message is the refusal
 * code.
 */
class TaxWaiverRefused extends RuntimeException {}
