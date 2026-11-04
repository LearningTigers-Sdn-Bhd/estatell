<?php

namespace App\Http\Requests\ApiAccess;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Staff add a kiosk. The route's `can:` middleware checks `settings.manage`.
 */
class StoreKioskRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
        ];
    }
}
