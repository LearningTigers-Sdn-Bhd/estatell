<?php

namespace App\Http\Requests\Property;

use App\Http\Requests\Concerns\ValidatesOtaListRows;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The rows of Settings → OTA lists. The route's `can:` middleware checks `settings.ota-lists`. The rules are shared with
 * onboarding (see ValidatesOtaListRows).
 */
class SaveOtaListsRequest extends FormRequest
{
    use ValidatesOtaListRows;

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $this->checkOtaListRows($validator);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->otaListRules();
    }
}
