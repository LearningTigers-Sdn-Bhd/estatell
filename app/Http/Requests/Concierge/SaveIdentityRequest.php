<?php

namespace App\Http\Requests\Concierge;

use App\Data\Kiosk\KioskIdentityData;
use App\Enums\IdentityDocumentType;
use App\Http\Requests\Kiosk\StoreStayIdentityRequest;
use App\Models\Property;
use App\Services\Concierge\ConciergeStays;
use App\Services\Kiosk\KioskStays;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The ID a guest typed on the Concierge page: the fields of the kiosk's ID, plus a photo of each side, optional unless the property requires it. A bad
 * form is an ordinary validation error, because the page shows it on the field.
 */
class SaveIdentityRequest extends StoreStayIdentityRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // The walk-in booking form extends this request and has no stay yet.
        $stay = $this->attributes->get('concierge.stay');
        $guest = $stay === null ? null : app(KioskStays::class)->guest($stay);

        // Walk-in and quote bookings have no stay yet, so the property comes from the route.
        $property = $stay === null ? $this->route('property') : $stay->property;
        $photoRequired = $property instanceof Property && (bool) $property->settings?->concierge_name_check_in_enabled;
        $myKad = $this->input('kind') === 'my_kad';
        // A photo already saved for this kind of document is not asked again.
        $photoSaved = $photoRequired && $guest !== null && app(ConciergeStays::class)->hasIdPhoto($guest, $myKad ? IdentityDocumentType::MyKad : IdentityDocumentType::Passport);
        $needsPhoto = $photoRequired && ! $photoSaved;

        return [
            ...parent::rules(),
            // A detail the profile already holds is not asked again, and the action keeps the saved one.
            'full_name' => filled($guest?->full_name) ? ['nullable', 'string', 'max:150'] : parent::rules()['full_name'],
            // Blank is allowed with `keep_saved_document`: the action then keeps the guest's saved document of that kind.
            'number' => ['required_without:keep_saved_document', 'nullable', 'string', 'min:4', 'max:40', Rule::when($this->input('kind') === 'my_kad', ['digits:12'])],
            'keep_saved_document' => ['nullable', 'boolean'],
            'email' => [filled($guest?->email) ? 'nullable' : 'required', 'email:rfc', 'max:254'],
            'phone' => [filled($guest?->phone) ? 'nullable' : 'required', 'string', 'regex:/^[0-9+()\s-]{6,30}$/'],
            'scan' => [$needsPhoto ? 'required' : 'nullable', 'image', 'max:8192'],
            'scan_back' => [$needsPhoto && $myKad ? 'required' : 'nullable', 'image', 'max:8192'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'scan.required' => __('Add a photo of your ID.'),
            'scan_back.required' => __('Add a photo of the back of your MyKad.'),
        ];
    }

    public function toIdentityData(): KioskIdentityData
    {
        $data = parent::toIdentityData();
        $data->email = $this->filled('email') ? $this->string('email')->toString() : null;
        $data->phone = $this->filled('phone') ? $this->string('phone')->toString() : null;

        return $data;
    }

    protected function failedValidation(Validator $validator): never
    {
        throw (new ValidationException($validator))->errorBag($this->errorBag)->redirectTo($this->getRedirectUrl());
    }
}
