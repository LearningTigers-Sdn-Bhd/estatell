<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\OnboardingStep;
use App\Enums\RegistrationCardScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * The Check-in essentials form of onboarding. The deposit switch is not stored: with it off the deposit is saved as 0,
 * with it on the amount must be above 0.
 */
class SaveCheckInEssentialsRequest extends OnboardingStepRequest
{
    protected function step(): OnboardingStep
    {
        return OnboardingStep::CheckInEssentials;
    }

    /**
     * The `onboarding.only` middleware limits who can reach this route.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'card_required_for_check_in' => $this->boolean('card_required_for_check_in'),
            'collect_deposit' => $this->boolean('collect_deposit'),
            'id_document_retention_days' => filled($this->input('id_document_retention_days')) ? $this->input('id_document_retention_days') : null,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'check_in_time' => ['required', 'date_format:H:i'],
            'check_out_time' => ['required', 'date_format:H:i'],
            'registration_card_scope' => ['required', Rule::enum(RegistrationCardScope::class)],
            'card_required_for_check_in' => ['required', 'boolean'],
            'id_document_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'collect_deposit' => ['required', 'boolean'],
            'security_deposit_amount' => [
                Rule::requiredIf($this->boolean('collect_deposit')),
                'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99',
            ],
        ];
    }

    /**
     * @return array{check_in_time: string, check_out_time: string, registration_card_scope: RegistrationCardScope, card_required_for_check_in: bool, id_document_retention_days: int|null, security_deposit_amount: string|null}
     */
    public function settings(): array
    {
        $data = $this->validated();

        return [
            'check_in_time' => $data['check_in_time'],
            'check_out_time' => $data['check_out_time'],
            'registration_card_scope' => RegistrationCardScope::from($data['registration_card_scope']),
            'card_required_for_check_in' => (bool) $data['card_required_for_check_in'],
            'id_document_retention_days' => isset($data['id_document_retention_days']) ? (int) $data['id_document_retention_days'] : null,
            'security_deposit_amount' => $data['collect_deposit'] ? (string) $data['security_deposit_amount'] : null,
        ];
    }
}
