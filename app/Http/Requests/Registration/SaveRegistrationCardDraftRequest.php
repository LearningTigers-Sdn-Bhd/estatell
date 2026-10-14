<?php

namespace App\Http\Requests\Registration;

use App\Data\Registration\RegistrationCardDraftData;
use App\Models\UnitGuest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

class SaveRegistrationCardDraftRequest extends FormRequest
{
    /** The `can:registration-card.sign` route middleware authorizes this request. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $identity = CardIdentityRules::normalize($this->input('identity'));

        if ($identity !== null) {
            $this->merge(['identity' => $identity]);
        }
    }

    /**
     * Staff may leave both empty. The guest's signing form checks what the card Layout requires.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...CardIdentityRules::for($this->unitGuest()->guest_id, $this->input('identity.type'), false, false),
            'vehicle_plate' => ['nullable', 'string', 'max:20'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function toDraftData(): RegistrationCardDraftData
    {
        $validated = $this->validated();

        return new RegistrationCardDraftData(
            identity: CardIdentityRules::newDocument($validated),
            identityDocumentId: isset($validated['identity_document_id']) ? (int) $validated['identity_document_id'] : null,
            vehiclePlate: $validated['vehicle_plate'] ?? null,
            remarks: $validated['remarks'] ?? null,
        );
    }

    private function unitGuest(): UnitGuest
    {
        $unitGuest = $this->route('unitGuest');

        return $unitGuest instanceof UnitGuest ? $unitGuest : throw new LogicException('The unit guest route binding is missing.');
    }
}
