<?php

namespace App\Http\Requests\Reservations;

use App\Enums\PermissionEnum;
use App\Http\Requests\Guests\GuestProfileRules;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AttachGuestRequest extends FormRequest
{
    /**
     * The `can:reservations.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An existing guest (`guest_id`, with its profile fields only when `update_profile` is on) or a new guest (the
     * profile fields with no `guest_id`).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'guest_id' => ['nullable', 'integer'],
            'update_profile' => ['nullable', 'boolean'],
            ...GuestProfileRules::for($this->phoneRegion(), nameRequired: false),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkGuest($validator)];
    }

    /**
     * Whether the request asks for a new guest or a profile update.
     */
    public function changesProfile(): bool
    {
        return ! $this->filled('guest_id') || $this->boolean('update_profile');
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        return $this->changesProfile()
            ? GuestProfileRules::attributes($this->safe()->except(['guest_id', 'update_profile']), $this->phoneRegion())
            : [];
    }

    /**
     * Creating a guest or changing a profile needs `guests.manage`, and a new guest needs a name.
     */
    private function checkGuest(Validator $validator): void
    {
        if (! $this->changesProfile()) {
            return;
        }

        if (! $this->user()->can(PermissionEnum::GuestsManage->value)) {
            $validator->errors()->add($this->filled('guest_id') ? 'update_profile' : 'full_name', $this->filled('guest_id')
                ? __('You may not change a guest\'s profile.')
                : __('Pick an existing guest.'));

            return;
        }

        if (! $this->filled('guest_id') && trim((string) $this->input('full_name')) === '') {
            $validator->errors()->add('full_name', __('Enter the guest\'s name or pick an existing guest.'));
        }
    }

    private function phoneRegion(): string
    {
        $property = $this->route('property');

        return $property instanceof Property ? ($property->country_code ?? 'MY') : 'MY';
    }
}
