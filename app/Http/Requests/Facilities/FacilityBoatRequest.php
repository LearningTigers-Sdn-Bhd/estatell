<?php

namespace App\Http\Requests\Facilities;

use App\Models\FacilityBoat;
use App\Models\Property;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Add or change a boat on the Facilities settings Boats tab. */
class FacilityBoatRequest extends FormRequest
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
        $boat = $this->route('facilityBoat');

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'seats' => filled($this->input('seats')) ? $this->input('seats') : null,
            'active' => $this->boolean('active', $boat instanceof FacilityBoat ? $boat->active : true),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');
        $boat = $this->route('facilityBoat');
        $boatId = $boat instanceof FacilityBoat ? $boat->id : null;

        return [
            'name' => [
                'required', 'string', 'max:60',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $boatId): void {
                    $taken = FacilityBoat::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($boatId !== null, fn ($query) => $query->whereKeyNot($boatId))
                        ->exists();

                    if ($taken) {
                        $fail(__('Another boat already uses this name.'));
                    }
                },
            ],
            'seats' => ['nullable', 'integer', 'min:1', 'max:999'],
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array{name: string, seats: ?int, active: bool}
     */
    public function boatAttributes(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'seats' => $this->validated('seats') === null ? null : (int) $this->validated('seats'),
            'active' => (bool) $this->validated('active'),
        ];
    }
}
