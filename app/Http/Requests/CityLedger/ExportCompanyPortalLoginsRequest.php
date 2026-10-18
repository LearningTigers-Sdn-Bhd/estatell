<?php

namespace App\Http\Requests\CityLedger;

use App\Enums\TemporaryPasswordExportFormat;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Company logins chosen for a temp password file, and the file format.
 */
class ExportCompanyPortalLoginsRequest extends CompanyPortalLoginUsersRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'format' => ['required', Rule::enum(TemporaryPasswordExportFormat::class)->except(TemporaryPasswordExportFormat::Message)],
        ];
    }

    public function exportFormat(): TemporaryPasswordExportFormat
    {
        return TemporaryPasswordExportFormat::from($this->validated('format'));
    }
}
