<?php

namespace App\Http\Requests\Pos;

use App\Enums\PosDiningKind;
use App\Enums\PosStationMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The route's middleware (`can:`) checks the permission; the Action checks what the data may do.
 */
class SavePosKitchenStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'mode' => ['required', Rule::enum(PosStationMode::class)],
            'use_internal_name' => ['nullable', 'boolean'],
            'warn_after_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'alert_after_minutes' => ['required', 'integer', 'min:2', 'max:1440'],
            'filter_dining_kinds' => ['nullable', 'array'],
            'filter_dining_kinds.*' => ['string', Rule::enum(PosDiningKind::class)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
