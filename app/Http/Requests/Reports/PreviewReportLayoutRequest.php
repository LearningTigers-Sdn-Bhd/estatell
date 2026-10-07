<?php

namespace App\Http\Requests\Reports;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The preview PDF of an unsaved layout: the dates of the report page (checked like the page) and the draft layout.
 */
class PreviewReportLayoutRequest extends ReportFiltersRequest
{
    use ReadsLayoutInput;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [...parent::rules(), ...$this->layoutRules()];
    }
}
