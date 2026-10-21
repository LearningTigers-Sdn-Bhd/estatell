<?php

namespace App\Services\Channels\Channex;

use RuntimeException;

/**
 * The call was not sent or Channex could not take it now: our own limiter, a 429, a 5xx or a network error. The
 * caller keeps the change, waits `retryAfter` seconds and backs off if it fails again. Nothing is dropped.
 */
class ChannexRetryLater extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter = 60, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
