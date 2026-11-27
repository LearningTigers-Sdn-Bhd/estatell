<?php

namespace App\Http\Requests\Transfers;

use App\Models\Property;
use App\Models\TransferDriver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Add or change a driver on the Transfers settings Drivers tab. An empty subcontractor means own staff. */
class TransferDriverRequest extends FormRequest
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
        $driver = $this->route('transferDriver');

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'phone' => filled($this->input('phone')) ? trim((string) $this->input('phone')) : null,
            'subcontractor' => filled($this->input('subcontractor')) ? trim((string) $this->input('subcontractor')) : null,
            'active' => $this->boolean('active', $driver instanceof TransferDriver ? $driver->active : true),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');
        $driver = $this->route('transferDriver');
        $driverId = $driver instanceof TransferDriver ? $driver->id : null;

        return [
            'name' => [
                'required', 'string', 'max:60',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $driverId): void {
                    $taken = TransferDriver::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($driverId !== null, fn ($query) => $query->whereKeyNot($driverId))
                        ->exists();

                    if ($taken) {
                        $fail(__('Another driver already uses this name.'));
                    }
                },
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'subcontractor' => ['nullable', 'string', 'max:60'],
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array{name: string, phone: ?string, subcontractor: ?string, active: bool}
     */
    public function driverAttributes(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'phone' => $this->validated('phone'),
            'subcontractor' => $this->validated('subcontractor'),
            'active' => (bool) $this->validated('active'),
        ];
    }
}
