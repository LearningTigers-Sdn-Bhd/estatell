<?php

namespace App\Services\EInvoice;

use Exception;

class MyInvoisApiException extends Exception
{
    /**
     * @param  array<string, mixed>|string|null  $responseBody
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array|string|null $responseBody = null,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function isTransient(): bool
    {
        return $this->statusCode === 408 || $this->statusCode === 429 || $this->statusCode >= 500;
    }
}
