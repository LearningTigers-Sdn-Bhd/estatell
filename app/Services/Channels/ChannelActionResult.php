<?php

namespace App\Services\Channels;

/**
 * The outcome of a channel action, for the controller's toast. A refusal is a result, not an exception.
 */
final readonly class ChannelActionResult
{
    public function __construct(public bool $ok, public string $message) {}

    public static function ok(string $message): self
    {
        return new self(true, $message);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
