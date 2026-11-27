<?php

namespace App\Http\Requests\Facilities;

use App\Models\FacilityGuide;
use App\Models\Property;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Add or change a guide on the Facilities settings Guides tab. */
class FacilityGuideRequest extends FormRequest
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
        $guide = $this->route('facilityGuide');

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'phone' => filled($this->input('phone')) ? trim((string) $this->input('phone')) : null,
            'active' => $this->boolean('active', $guide instanceof FacilityGuide ? $guide->active : true),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');
        $guide = $this->route('facilityGuide');
        $guideId = $guide instanceof FacilityGuide ? $guide->id : null;

        return [
            'name' => [
                'required', 'string', 'max:60',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $guideId): void {
                    $taken = FacilityGuide::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($guideId !== null, fn ($query) => $query->whereKeyNot($guideId))
                        ->exists();

                    if ($taken) {
                        $fail(__('Another guide already uses this name.'));
                    }
                },
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array{name: string, phone: ?string, active: bool}
     */
    public function guideAttributes(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'phone' => $this->validated('phone'),
            'active' => (bool) $this->validated('active'),
        ];
    }
}
