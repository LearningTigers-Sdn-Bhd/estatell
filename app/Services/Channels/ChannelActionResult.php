<?php

namespace App\Services\Channels;

/**
 * The outcome of a channel action, for the controller's toast. A refusal is a result, not an exception. `retryable`
 * marks a failure that may pass by itself, so a background job tries again.
 */
final readonly class ChannelActionResult
{
    public function __construct(public bool $ok, public string $message, public bool $retryable = false) {}

    public static function ok(string $message): self
    {
        return new self(true, $message);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }

    /**
     * A failure that may go away on its own (a rate limit, an outage): a background job tries again later.
     */
    public static function failedForNow(string $message): self
    {
        return new self(false, $message, retryable: true);
    }
}
