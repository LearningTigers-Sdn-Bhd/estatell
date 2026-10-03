<?php

namespace App\Services\Folio;

use App\Actions\Folio\RoutingRuleResult;

/**
 * Turns the refusal codes of `CreateRoutingRule` and `RemoveRoutingRule` into a message and the form field it belongs
 * to, the way `FolioRefusals` does. The add form shows `errors()`; the remove AlertDialog shows `message()` as a toast.
 * See docs/13-folio/03-routing.md#staff-screens.
 */
class RoutingRuleRefusals
{
    public const string FORM = 'form';

    /**
     * The errors to flash back to the add form, keyed by field name.
     *
     * @return array<string, string>
     */
    public function errors(string $code): array
    {
        [$field, $message] = $this->entry($code);

        return [$field => $message];
    }

    /**
     * The message alone, for a toast.
     */
    public function message(string $code): string
    {
        return $this->entry($code)[1];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function entry(string $code): array
    {
        return match ($code) {
            RoutingRuleCheck::REFUSAL_TARGET_FOLIO_NOT_IN_RESERVATION => ['target_folio_id', __('The folio must belong to this reservation.')],
            RoutingRuleCheck::REFUSAL_UNIT_NOT_IN_RESERVATION => ['reservation_unit_id', __('The unit must belong to this reservation.')],
            RoutingRuleCheck::REFUSAL_TRANSACTION_CODE_NOT_FOUND => ['transaction_code_id', __('This charge code is not available.')],
            RoutingRuleResult::REFUSAL_RULE_EXISTS => [self::FORM, __('A rule for this unit and these charges already exists. Remove it first.')],
            RoutingRuleResult::REFUSAL_TARGET_FOLIO_NOT_OPEN => ['target_folio_id', __('Choose an open folio.')],
            RoutingRuleResult::REFUSAL_RULE_NOT_MANUAL => [self::FORM, __('This rule comes from the billing mode. Change the billing mode to change it.')],
            RoutingRuleResult::REFUSAL_RULE_NOT_FOUND => [self::FORM, __('This rule was already removed.')],
            default => [self::FORM, __('This could not be done.')],
        };
    }
}
