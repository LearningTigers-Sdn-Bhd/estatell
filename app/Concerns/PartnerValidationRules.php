<?php

namespace App\Concerns;

use App\Models\PartnerInvitation;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The prefix and email rules every way of adding a partner shares (add, invite, bulk add), so they cannot drift.
 * Callers upper-case the prefix and lower-case the email before validating.
 */
trait PartnerValidationRules
{
    /**
     * The prefix must be two capital letters, not ES, and free in the property code counters.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function partnerPrefixRules(): array
    {
        return [
            'required',
            'string',
            'regex:/^[A-Z]{2}$/',
            'not_in:ES',
            Rule::unique('property_code_counters', 'prefix'),
        ];
    }

    /**
     * The email must not belong to an account or hold an open partner invitation, compared in lower case.
     *
     * @return array<int, ValidationRule|Closure|array<mixed>|string>
     */
    protected function partnerEmailRules(): array
    {
        return [
            'required',
            'string',
            'lowercase',
            'email',
            'max:255',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (User::query()->whereRaw('lower(email) = ?', [$value])->exists()) {
                    $fail(__('The email has already been taken.'));
                }

                if (PartnerInvitation::query()->open()->whereRaw('lower(email) = ?', [$value])->exists()) {
                    $fail(__('This email already has an open partner invitation.'));
                }
            },
        ];
    }

    /**
     * Messages for the prefix rules, keyed for the given attribute (for example `code_prefix` or
     * `rows.*.code_prefix`).
     *
     * @return array<string, string>
     */
    protected function partnerPrefixMessages(string $attribute): array
    {
        return [
            "{$attribute}.regex" => __('The prefix must be two capital letters.'),
            "{$attribute}.not_in" => __('ES is reserved for direct properties.'),
            "{$attribute}.unique" => __('This prefix is already taken.'),
        ];
    }
}
