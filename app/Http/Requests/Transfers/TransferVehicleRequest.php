<?php

namespace App\Http\Requests\Transfers;

use App\Models\Property;
use App\Models\TransferVehicle;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Add or change a vehicle on the Transfers settings Vehicles tab. */
class TransferVehicleRequest extends FormRequest
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
        $vehicle = $this->route('transferVehicle');

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'plate' => filled($this->input('plate')) ? trim((string) $this->input('plate')) : null,
            'seats' => filled($this->input('seats')) ? $this->input('seats') : null,
            'active' => $this->boolean('active', $vehicle instanceof TransferVehicle ? $vehicle->active : true),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');
        $vehicle = $this->route('transferVehicle');
        $vehicleId = $vehicle instanceof TransferVehicle ? $vehicle->id : null;

        return [
            'name' => [
                'required', 'string', 'max:60',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $vehicleId): void {
                    $taken = TransferVehicle::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($vehicleId !== null, fn ($query) => $query->whereKeyNot($vehicleId))
                        ->exists();

                    if ($taken) {
                        $fail(__('Another vehicle already uses this name.'));
                    }
                },
            ],
            'plate' => ['nullable', 'string', 'max:20'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:999'],
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array{name: string, plate: ?string, seats: ?int, active: bool}
     */
    public function vehicleAttributes(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'plate' => $this->validated('plate'),
            'seats' => $this->validated('seats') === null ? null : (int) $this->validated('seats'),
            'active' => (bool) $this->validated('active'),
        ];
    }
}
