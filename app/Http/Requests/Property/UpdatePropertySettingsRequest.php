<?php

namespace App\Http\Requests\Property;

use App\Enums\Currency;
use App\Enums\Locale;
use App\Enums\MissedArrivalPolicy;
use App\Enums\NoShowCharge;
use App\Enums\OverdueDeparturePolicy;
use App\Enums\RegistrationCardScope;
use App\Models\Property;
use App\Services\Property\SettingsLock;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePropertySettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $booleanFields = match ($this->route('section')) {
            'general' => ['auto_assign_rooms'],
            'operations' => ['auto_audit_enabled'],
            'registration' => ['card_required_for_check_in'],
            'support' => ['allow_support_access'],
            default => [],
        };

        foreach ($booleanFields as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }
    }

    /**
     * Get the validation rules that apply to the request. Locked fields are dropped, not rejected.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(SettingsLock $settingsLock): array
    {
        /** @var Property $property */
        $property = $this->route('property');
        $locked = $settingsLock->locksCurrencyAndTimeZone($property);

        return match ($this->route('section')) {
            'general' => [
                'currency' => $locked ? ['exclude'] : ['required', Rule::enum(Currency::class)],
                'time_zone' => $locked ? ['exclude'] : ['required', 'timezone'],
                'default_locale' => ['required', Rule::enum(Locale::class)],
                'check_in_time' => ['required', 'date_format:H:i'],
                'check_out_time' => ['required', 'date_format:H:i'],
                'arrival_grace_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
                'auto_assign_rooms' => ['required', 'boolean'],
            ],
            'operations' => [
                'audit_time' => ['required', 'date_format:H:i'],
                'auto_audit_enabled' => ['required', 'boolean'],
                'missed_arrival_policy' => ['required', Rule::enum(MissedArrivalPolicy::class)],
                'overdue_departure_policy' => ['required', Rule::enum(OverdueDeparturePolicy::class)],
                'no_show_charge' => ['required', Rule::enum(NoShowCharge::class)],
            ],
            'registration' => [
                'registration_card_scope' => ['required', Rule::enum(RegistrationCardScope::class)],
                'card_required_for_check_in' => ['required', 'boolean'],
                'id_document_retention_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
                'security_deposit_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            ],
            'support' => ['allow_support_access' => ['required', 'boolean']],
            default => [],
        };
    }
}
