<?php

namespace App\Http\Requests\Portal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** A payment proof from the portal: the file (image or PDF, at most 10 MB), the amount paid and the bank reference. */
class UploadPaymentProofRequest extends FormRequest
{
    /**
     * The `portal` route middleware checks the company link; the controller finds the booking through it.
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
        return [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
