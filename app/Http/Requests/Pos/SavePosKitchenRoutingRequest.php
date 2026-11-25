<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Category and item routing as two maps of id to station id (null to unroute). The Action checks each id belongs here.
 */
class SavePosKitchenRoutingRequest extends FormRequest
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
            'categories' => ['present', 'array'],
            'categories.*' => ['nullable', 'integer'],
            'items' => ['present', 'array'],
            'items.*' => ['nullable', 'integer'],
        ];
    }
}
