<?php

namespace App\Http\Requests\Admin;

use App\Models\PropertyGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PropertyGroupRequest extends FormRequest
{
    /**
     * The `can:admin` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100']];
    }

    /**
     * Names are unique ignoring case, as the index is.
     *
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $group = $this->route('propertyGroup');
            $exceptId = $group instanceof PropertyGroup ? $group->id : null;
            $taken = PropertyGroup::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $this->input('name')))])
                ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
                ->exists();

            if ($taken) {
                $validator->errors()->add('name', __('Another group already has this name.'));
            }
        }];
    }
}
