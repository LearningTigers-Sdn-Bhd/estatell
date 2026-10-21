<?php

namespace App\Services\Channels\Channex;

use RuntimeException;

/**
 * Channex refused the call and retrying will not help: a 422 validation error, a 401, a 404 and so on.
 */
class ChannexRequestFailed extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(string $message, public readonly int $status, public readonly array $errors = [])
    {
        parent::__construct($message, $status);
    }
}
