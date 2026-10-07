<?php

namespace App\Http\Requests\NightAudit;

use Illuminate\Foundation\Http\FormRequest;

class RunAuditFixRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unit_id' => ['nullable', 'integer'],
        ];
    }
}
