<?php

namespace App\Http\Requests\Boats;

use App\Models\Boat;
use App\Models\Property;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Add or change a boat on the Boats tab. */
class BoatRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'registration' => filled($this->input('registration')) ? trim((string) $this->input('registration')) : null,
            'active' => $this->boolean('active', true),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');
        $boat = $this->route('boat');
        $boatId = $boat instanceof Boat ? $boat->id : null;

        return [
            'name' => [
                'required', 'string', 'max:60',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $boatId): void {
                    $taken = Boat::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($boatId !== null, fn ($query) => $query->whereKeyNot($boatId))
                        ->exists();

                    if ($taken) {
                        $fail(__('Another boat already uses this name.'));
                    }
                },
            ],
            'registration' => ['nullable', 'string', 'max:30'],
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array{name: string, registration: ?string, active: bool}
     */
    public function boatAttributes(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'registration' => $this->validated('registration'),
            'active' => (bool) $this->validated('active'),
        ];
    }
}
