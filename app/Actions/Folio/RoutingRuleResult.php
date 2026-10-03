<?php

namespace App\Actions\Folio;

use App\Models\FolioRoutingRule;

/**
 * What `CreateRoutingRule` and `RemoveRoutingRule` return: the saved or removed rule, or a refusal code (the
 * `RoutingRuleCheck` codes, or one of the codes below).
 */
class RoutingRuleResult
{
    public const REFUSAL_RULE_EXISTS = 'rule_exists';

    public const REFUSAL_TARGET_FOLIO_NOT_OPEN = 'target_folio_not_open';

    public const REFUSAL_RULE_NOT_MANUAL = 'rule_not_manual';

    public const REFUSAL_RULE_NOT_FOUND = 'rule_not_found';

    private function __construct(
        public readonly ?FolioRoutingRule $rule,
        public readonly ?string $refusal,
    ) {}

    public static function created(FolioRoutingRule $rule): self
    {
        return new self($rule, null);
    }

    public static function removed(FolioRoutingRule $rule): self
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
