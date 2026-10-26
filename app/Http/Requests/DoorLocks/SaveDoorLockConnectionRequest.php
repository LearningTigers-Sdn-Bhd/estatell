<?php

namespace App\Http\Requests\DoorLocks;

use App\Enums\PasscodeMode;
use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The connection form of Settings → Door locks. The route's `can:` middleware checks `settings.manage`. The password is needed
 * to sign in the first time and whenever the username changes, otherwise a blank one keeps the saved sign-in.
 */
class SaveDoorLockConnectionRequest extends FormRequest
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
            'ttlock_username' => ['required', 'string', 'max:255'],
            'ttlock_password' => ['nullable', 'string', 'max:255'],
            'passcode_mode' => ['required', Rule::enum(PasscodeMode::class)],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $property = $this->route('property');
            $saved = $property instanceof Property ? $property->doorLockConnection : null;
            $signedIn = $saved !== null && filled($saved->refresh_token) && $saved->ttlock_username === trim((string) $this->input('ttlock_username'));

            if (! $signedIn && blank($this->input('ttlock_password'))) {
                $validator->errors()->add('ttlock_password', __('Enter the TTLock password to sign in. It is not saved.'));
            }
        });
    }

    /**
     * @return array{ttlock_username: string, ttlock_password: string|null, enabled: bool, passcode_mode: string}
     */
    public function connection(): array
    {
        return [
            'ttlock_username' => (string) $this->input('ttlock_username'),
            'ttlock_password' => $this->filled('ttlock_password') ? (string) $this->input('ttlock_password') : null,
            'enabled' => $this->boolean('enabled'),
            'passcode_mode' => (string) $this->input('passcode_mode'),
        ];
    }
}
