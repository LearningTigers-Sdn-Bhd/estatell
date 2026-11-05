<?php

namespace App\Http\Requests\ApiAccess;

use App\Enums\KioskKeyMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff change the kiosk settings. The route's `can:` middleware checks `settings.manage`.
 */
class UpdateKioskSettingsRequest extends FormRequest
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
            'key_mode' => ['required', Rule::enum(KioskKeyMode::class)],
            'walk_in_enabled' => ['required', 'boolean'],
            'idle_timeout_seconds' => ['required', 'integer', 'min:15', 'max:600'],
        ];
    }
}
