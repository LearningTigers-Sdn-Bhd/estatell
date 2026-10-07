<?php

namespace App\Actions\NightAudit;

use App\Models\NightAudit;

/**
 * What `ClaimBusinessDate` and `RunNightAudit` return: the audit and its state, or a refusal code.
 * `claimed` is the audit just after the claim, `completed` a finished run and `failed` a run that stopped at a step
 * and waits to be resumed. A refusal writes nothing.
 */
class NightAuditResult
{
    public const REFUSAL_NO_BUSINESS_DATE = 'no_business_date';

    public const REFUSAL_ALREADY_RUNNING = 'already_running';

    public const REFUSAL_REASON_REQUIRED = 'reason_required';

    private const CLAIMED = 'claimed';

    private const COMPLETED = 'completed';

    private const FAILED = 'failed';

    private function __construct(
        public readonly ?NightAudit $audit,
        public readonly ?string $refusal,
        private readonly ?string $state,
    ) {}

    public static function claimed(NightAudit $audit): self
    {
        return new self($audit, null, self::CLAIMED);
    }

    public static function completed(NightAudit $audit): self
    {
        return new self($audit, null, self::COMPLETED);
    }

    public static function failed(NightAudit $audit): self
    {
        return new self($audit, null, self::FAILED);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, $refusal, null);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }

    public function isCompleted(): bool
    {
        return $this->state === self::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->state === self::FAILED;
    }
}
