<?php

namespace App\Http\Requests\CityLedger;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** The property's agent payment terms: the deposit share, its hours and the full payment days. */
class AgentPaymentTermsRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'agent_deposit_percent' => ['required', 'integer', 'between:1,100'],
            'agent_deposit_hours' => ['required', 'integer', 'between:1,8760'],
            'agent_full_payment_days' => ['required', 'integer', 'between:1,365'],
        ];
    }

    /**
     * @return array{agent_deposit_percent: int, agent_deposit_hours: int, agent_full_payment_days: int}
     */
    public function terms(): array
    {
        return [
            'agent_deposit_percent' => $this->integer('agent_deposit_percent'),
            'agent_deposit_hours' => $this->integer('agent_deposit_hours'),
            'agent_full_payment_days' => $this->integer('agent_full_payment_days'),
        ];
    }
}
