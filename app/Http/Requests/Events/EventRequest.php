<?php

namespace App\Http\Requests\Events;

use App\Enums\CompanyStatus;
use App\Enums\EventStatus;
use App\Enums\PermissionEnum;
use App\Models\Guest;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * New event and Edit event. The `can:events.manage` route middleware authorizes it. The payer is a guest of the
 * property, a new guest (New event only, with `guests.manage`) or an Active company (with `company.view`).
 * See docs/33-facilities/03-events-and-event-folios.md.
 */
class EventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->route('property');
        $propertyId = $property instanceof Property ? $property->id : null;
        $isNew = $this->route('event') === null;

        return [
            'name' => ['required', 'string', 'max:150'],
            'payer_type' => ['required', Rule::in(array_values(array_filter([
                'guest',
                $isNew && $this->user()->can(PermissionEnum::GuestsManage->value) ? 'new_guest' : null,
                $this->user()->can(PermissionEnum::CompanyView->value) ? 'company' : null,
            ])))],
            'payer_guest_id' => [
                'exclude_unless:payer_type,guest',
                'required',
                'integer',
                Rule::exists('guests', 'id')->where('property_id', $propertyId)->whereNull('merged_into_id')->whereNull('anonymized_at'),
            ],
            'new_guest_name' => ['exclude_unless:payer_type,new_guest', 'required', 'string', 'max:150'],
            'payer_company_id' => [
                'exclude_unless:payer_type,company',
                'required',
                'integer',
                Rule::exists('companies', 'id')->where('property_id', $propertyId)->where('status', CompanyStatus::Active->value),
            ],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'status' => [Rule::excludeIf(! $isNew), 'required', Rule::in([EventStatus::Tentative->value, EventStatus::Confirmed->value])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payer_guest_id.required' => __('Choose a guest.'),
            'payer_company_id.required' => __('Choose a company.'),
            'new_guest_name.required' => __('Enter the guest\'s name.'),
            'ends_on.after_or_equal' => __('The last day cannot be before the first day.'),
        ];
    }

    /**
     * The validated fields in the shape `CreateEvent` and `UpdateEvent` take.
     *
     * @return array{name: string, payer_guest_id: ?int, payer_company_id: ?int, new_guest: array{full_name: string}|null, starts_on: string, ends_on: string, status: EventStatus, notes: ?string}
     */
    public function eventAttributes(): array
    {
        $type = $this->string('payer_type')->toString();

        return [
            'name' => trim($this->string('name')->toString()),
            'payer_guest_id' => $type === 'guest' ? $this->integer('payer_guest_id') : null,
            'payer_company_id' => $type === 'company' ? $this->integer('payer_company_id') : null,
            'new_guest' => $type === 'new_guest' ? ['full_name' => trim($this->string('new_guest_name')->toString())] : null,
            'starts_on' => $this->string('starts_on')->toString(),
            'ends_on' => $this->string('ends_on')->toString(),
            'status' => EventStatus::tryFrom($this->string('status')->toString()) ?? EventStatus::Confirmed,
            'notes' => $this->filled('notes') ? trim($this->string('notes')->toString()) : null,
        ];
    }
}
