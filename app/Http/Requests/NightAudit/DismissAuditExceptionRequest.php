<?php

namespace App\Http\Requests\NightAudit;

use Illuminate\Foundation\Http\FormRequest;

class DismissAuditExceptionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
