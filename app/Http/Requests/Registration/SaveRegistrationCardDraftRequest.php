<?php

namespace App\Http\Requests\Registration;

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
        ];
    }

    /**
     * @return array{identity: array{type: string, number: string, issuing_country_code: string|null, expires_on: string|null}|null, identity_document_id: int|null, vehicle_plate: string|null}
     */
    public function actionInput(): array
    {
        $validated = $this->validated();

        return [
            'identity' => CardIdentityRules::newDocument($validated),
            'identity_document_id' => isset($validated['identity_document_id']) ? (int) $validated['identity_document_id'] : null,
            'vehicle_plate' => $validated['vehicle_plate'] ?? null,
        ];
    }

    private function unitGuest(): UnitGuest
    {
        $unitGuest = $this->route('unitGuest');

        return $unitGuest instanceof UnitGuest ? $unitGuest : throw new LogicException('The unit guest route binding is missing.');
    }
}
