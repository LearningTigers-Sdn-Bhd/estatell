<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * Curlec could not be reached, or refused a request. The message holds no keys or guest details.
 */
class CurlecRequestFailed extends RuntimeException {}
