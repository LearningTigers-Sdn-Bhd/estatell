<?php

namespace App\Actions\Folio;

use App\Models\FolioRoutingRule;

/**
 * What `CreateRoutingRule` returns: the saved rule, or a refusal code (the `RoutingRuleCheck` codes, or `rule_exists`).
 */
class RoutingRuleResult
{
    public const REFUSAL_RULE_EXISTS = 'rule_exists';

    private function __construct(
        public readonly ?FolioRoutingRule $rule,
        public readonly ?string $refusal,
    ) {}

    public static function created(FolioRoutingRule $rule): self
    {
        return new self($rule, null);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
