<?php

namespace App\Http\Requests\Reports;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A saved layout: the orientation and the full column list in order. The `can:` route middleware authorizes it.
 */
class UpdateReportLayoutRequest extends FormRequest
{
    use ReadsLayoutInput;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->layoutRules();
    }
}
