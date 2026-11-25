<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A guest proving they hold a receipt: the exact total and the date printed on it. No login, so the rate limits on the route
 * and the attempt count on the order are what guard it.
 */
class ConfirmPosReceiptClaimRequest extends FormRequest
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
            'total' => ['required', 'string', 'max:20'],
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
