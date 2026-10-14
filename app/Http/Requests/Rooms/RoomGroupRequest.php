<?php

namespace App\Http\Requests\Rooms;

use App\Models\Property;
use App\Models\RoomGroup;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RoomGroupRequest extends FormRequest
{
    /**
     * The `can:rooms.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');
        $current = $this->route('roomGroup');

        return [
            'name' => [
                'required', 'string', 'max:100',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $current): void {
                    $taken = RoomGroup::query()
                        ->where('property_id', $property->id)
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($current instanceof RoomGroup ? $current->id : null, fn ($query, int $id) => $query->whereKeyNot($id))
                        ->exists();

                    if ($taken) {
                        $fail(__('Another room group already uses this name.'));
                    }
                },
            ],
        ];
    }
}
