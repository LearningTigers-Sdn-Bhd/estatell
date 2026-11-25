<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A manager registers the browser in hand as a POS till. The route's `can:` middleware checks `pos.manage`.
 */
class RegisterPosTerminalDeviceRequest extends FormRequest
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
            'pos_outlet_id' => ['required', 'integer'],
        ];
    }
}
