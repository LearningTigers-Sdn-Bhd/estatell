<?php

namespace App\Http\Requests\Pos;

use App\Enums\PosTenderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The route's middleware (`pos.can:` or `can:`) checks the permission; the Action checks what the data may do.
 */
class ChangePosPaymentRequest extends FormRequest
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
            'payment_id' => ['required', 'integer'],
            'tender_type' => ['required', Rule::enum(PosTenderType::class)],
            'payment_method_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
