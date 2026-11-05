<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * Curlec answered that this refund cannot run, and asking again will not help. The message is Curlec's own and holds no
 * keys or guest details.
 */
class CurlecRefundRefused extends RuntimeException {}
