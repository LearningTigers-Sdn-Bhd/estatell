<?php

namespace App\Services\Pos\Loyverse;

use RuntimeException;

/**
 * A Loyverse call that did not succeed. `retryAfter` is set for a rate limit (HTTP 429, 300 requests per 300 seconds per
 * account), so a job releases itself for that many seconds. `reauthRequired` is set when the merchant revoked access or
 * the refresh token is no longer valid: retrying cannot help until the merchant connects again.
 */
class LoyverseException extends RuntimeException
{
    private function __construct(string $message, public readonly ?int $status = null, public readonly ?int $retryAfter = null, public readonly bool $reauthRequired = false)
    {
        parent::__construct($message);
    }

    public static function failed(int $status, string $detail = ''): self
    {
        return new self(trim('Loyverse answered HTTP '.$status.'. '.$detail), $status);
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self('Loyverse rate limit reached.', 429, max(1, $retryAfter));
    }

    public static function reauthRequired(): self
    {
        return new self('Loyverse access was revoked or expired. Connect Loyverse again.', 401, null, true);
    }
}
