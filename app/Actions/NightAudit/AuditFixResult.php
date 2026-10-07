<?php

namespace App\Actions\NightAudit;

/**
 * What `RunAuditFix` did: how many units it fixed and the refusal code of each unit it could not.
 */
class AuditFixResult
{
    /**
     * @param  array<int, string>  $refusals  Refusal code by reservation unit id.
     */
    public function __construct(
        public readonly int $done,
        public readonly array $refusals,
    ) {}

    public function isNothingToDo(): bool
    {
        return $this->done === 0 && $this->refusals === [];
    }
}
