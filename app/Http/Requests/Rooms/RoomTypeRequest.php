<?php

namespace App\Http\Requests\Rooms;

use App\Enums\SellUnit;
use App\Models\Property;
use App\Models\RoomType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RoomTypeRequest extends FormRequest
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
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'max_children' => $this->input('max_children', 0),
            'description' => filled($this->input('description')) ? trim((string) $this->input('description')) : null,
        ]);

        if ($this->has('terms_document_id')) {
            $this->merge(['terms_document_id' => filled($this->input('terms_document_id')) ? $this->input('terms_document_id') : null]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Property $property */
        $property = $this->route('property');
        $current = $this->route('roomType');

        return [
            'name' => [
                'required', 'string', 'max:100',
                function (string $attribute, mixed $value, Closure $fail) use ($property, $current): void {
                    $taken = RoomType::query()
                        ->where('property_id', $property->id)
                        ->active()
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($current instanceof RoomType ? $current->id : null, fn ($query, int $id) => $query->whereKeyNot($id))
                        ->exists();

                    if ($taken) {
                        $fail(__('Another active room type already uses this name.'));
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'sell_unit' => [
                'required', Rule::enum(SellUnit::class),
                function (string $attribute, mixed $value, Closure $fail) use ($current): void {
                    if ($current instanceof RoomType && $current->sell_unit->value !== $value && $current->rooms()->exists()) {
                        $fail(__('Sold by cannot change while this room type has rooms.'));
                    }
                },
            ],
            'max_adults' => ['required', 'integer', 'min:1', 'max:50'],
            'max_children' => ['required', 'integer', 'min:0', 'max:50'],
            'base_occupancy' => ['required', 'integer', 'min:1'],
            'smoking_allowed' => ['boolean'],
            'pets_allowed' => ['boolean'],
            'terms_document_id' => ['nullable', 'integer', Rule::exists('terms_documents', 'id')->where('property_id', $property->id)],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['max_adults', 'max_children', 'base_occupancy'])) {
                    return;
                }

                if ($this->integer('base_occupancy') > $this->integer('max_adults') + $this->integer('max_children')) {
                    $validator->errors()->add('base_occupancy', __('Guests included cannot be more than the maximum guests.'));
                }
            },
        ];
    }
}
