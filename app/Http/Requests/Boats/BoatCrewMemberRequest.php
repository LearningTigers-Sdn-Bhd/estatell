<?php

namespace App\Http\Requests\Boats;

use App\Models\BoatCrewMember;
use App\Models\Property;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Propaganistas\LaravelPhone\PhoneNumber;
use Propaganistas\LaravelPhone\Rules\Phone;

/** Add or change a crew member on the Crew tab. */
class BoatCrewMemberRequest extends FormRequest
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
        $crewMember = $this->route('crewMember');

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'phone' => filled($this->input('phone')) ? trim((string) $this->input('phone')) : null,
            // The Dialog has no Available switch, so an edit that sends none keeps the person's own state.
            'active' => $this->boolean('active', $crewMember instanceof BoatCrewMember ? $crewMember->active : true),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');
        $crewMember = $this->route('crewMember');
        $crewMemberId = $crewMember instanceof BoatCrewMember ? $crewMember->id : null;

        return [
            'name' => [
                'required', 'string', 'max:60',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $crewMemberId): void {
                    $taken = BoatCrewMember::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($crewMemberId !== null, fn ($query) => $query->whereKeyNot($crewMemberId))
                        ->exists();

                    if ($taken) {
                        $fail(__('Another crew member already uses this name.'));
                    }
                },
            ],
            'phone' => ['nullable', 'string', 'max:30', (new Phone)->country($this->phoneRegion())->international()],
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array{name: string, phone: ?string, active: bool}
     */
    public function crewMemberAttributes(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'phone' => filled($this->validated('phone')) ? (new PhoneNumber((string) $this->validated('phone'), $this->phoneRegion()))->formatE164() : null,
            'active' => (bool) $this->validated('active'),
        ];
    }

    /** A number typed without a country code is read as a number of the property's country. */
    private function phoneRegion(): string
    {
        /** @var Property $property */
        $property = $this->route('property');

        return $property->country_code ?? 'MY';
    }
}
