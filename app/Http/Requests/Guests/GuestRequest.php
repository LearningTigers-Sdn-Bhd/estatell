<?php

namespace App\Http\Requests\Guests;

use App\Data\Guests\IdentityDocumentInputData;
use App\Enums\IdentityDocumentType;
use App\Enums\PermissionEnum;
use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Services\Guests\CountryOptions;
use App\Services\Guests\IdentityNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use LogicException;

class GuestRequest extends FormRequest
{
    /**
     * The `can:guests.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return ! ($this->filled('identity_number') || $this->filled('identity_document_id') || $this->hasFile('identity_photo'))
            || $this->user()->can(PermissionEnum::GuestsViewDocuments->value);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('identity_number'))) {
            $this->merge(['identity_number' => app(IdentityNumber::class)->normalize($this->input('identity_number'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...GuestProfileRules::for($this->phoneRegion()),
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['required', 'string', 'max:50'],
            'identity_document_id' => ['nullable', 'integer', Rule::exists('guest_identity_documents', 'id')->where('guest_id', $this->identityGuestId())],
            'identity_number' => ['nullable', 'required_with:identity_photo,identity_document_id', 'string', 'min:4', 'max:40', Rule::when($this->input('identity_type', 'mykad') === 'mykad', ['digits:12'])],
            'identity_type' => ['required_with:identity_number', Rule::enum(IdentityDocumentType::class)],
            'identity_country_code' => ['nullable', Rule::in(app(CountryOptions::class)->codes())],
            'identity_photo' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.IdentityDocumentRequest::MAX_SCAN_KILOBYTES],
        ];
    }

    /**
     * The profile fields for the model, with the phone number in E.164.
     *
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        return GuestProfileRules::attributes($this->safe()->except(['tags', 'identity_document_id', 'identity_number', 'identity_type', 'identity_country_code', 'identity_photo']), $this->phoneRegion());
    }

    /**
     * @return list<string>
     */
    public function tagNames(): array
    {
        return array_values($this->validated('tags') ?? []);
    }

    public function identityData(): ?IdentityDocumentInputData
    {
        if (! $this->filled('identity_number')) {
            return null;
        }

        return new IdentityDocumentInputData(
            IdentityDocumentType::from($this->validated('identity_type')),
            $this->validated('identity_number'),
            $this->validated('identity_country_code') ?: null,
            null,
        );
    }

    public function identityDocumentId(): ?int
    {
        return $this->filled('identity_document_id') ? $this->integer('identity_document_id') : null;
    }

    private function identityGuestId(): ?int
    {
        $guest = $this->route('guest');
        if ($guest instanceof Guest) {
            return $guest->id;
        }
        if ($this->route('guestId') !== null) {
            return (int) $this->route('guestId');
        }
        $reservation = $this->route('reservation');

        return $reservation instanceof Reservation ? $reservation->booker_guest_id : null;
    }

    public function identityPhoto(): ?UploadedFile
    {
        return $this->file('identity_photo');
    }

    /**
     * Numbers typed without a country code are read as the property's country.
     */
    private function phoneRegion(): string
    {
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return $property->country_code ?? 'MY';
    }
}
