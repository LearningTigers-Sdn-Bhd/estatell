<?php

namespace App\Http\Requests\Admin;

use App\Data\Platform\OtaCredentialLoginData;
use App\Models\OtaChannel;
use App\Models\Property;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One OTA login an admin adds or changes. The admin route group checks access. A saved row keeps its channel, so the
 * channel fields apply only when adding. A saved row may leave the password blank to keep the saved one.
 */
class SaveOtaCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'channel_name' => trim((string) $this->input('channel_name')),
            'property_code' => trim((string) $this->input('property_code')),
            'username' => trim((string) $this->input('username')),
            'market_manager_name' => trim((string) $this->input('market_manager_name')),
            'market_manager_phone' => trim((string) $this->input('market_manager_phone')),
            'market_manager_email' => Str::lower(trim((string) $this->input('market_manager_email'))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...($this->isAdding() ? [
                'ota_channel_id' => [
                    'required', 'integer',
                    Rule::exists('ota_channels', 'id')->where(fn (Builder $query) => $query->where('is_active', true)),
                ],
                'channel_name' => $this->isOther() ? ['required', 'string', 'max:80'] : ['nullable', 'string', 'max:80'],
            ] : []),
            'property_code' => ['required', 'string', 'max:80'],
            'username' => ['required', 'string', 'max:255'],
            'password' => [$this->isAdding() ? 'required' : 'nullable', 'string', 'max:255'],
            'market_manager_name' => ['nullable', 'string', 'max:255'],
            'market_manager_phone' => ['nullable', 'string', 'max:40'],
            'market_manager_email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ota_channel_id.required' => __('Choose a channel.'),
            'ota_channel_id.exists' => __('Choose one of the listed channels.'),
            'channel_name.required' => __('Enter the channel name.'),
            'property_code.required' => __('Enter the property ID on this channel.'),
            'username.required' => __('Enter a username.'),
            'password.required' => __('Enter a password.'),
            'market_manager_email.email' => __('Enter a valid email.'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $afterValidator): void {
            $property = $this->route('property');

            if (! $this->isAdding() || ! $property instanceof Property || $afterValidator->errors()->isNotEmpty()) {
                return;
            }

            $taken = $property->otaCredentials()
                ->where('ota_channel_id', $this->integer('ota_channel_id'))
                ->get(['channel_name'])
                ->contains(fn ($saved): bool => Str::lower((string) $saved->channel_name) === Str::lower((string) $this->channelName()));

            if ($taken) {
                $afterValidator->errors()->add(
                    $this->isOther() ? 'channel_name' : 'ota_channel_id',
                    $this->isOther() ? __('This channel name is already in the list.') : __('This channel is already in the list.'),
                );
            }
        });
    }

    public function channel(): OtaChannel
    {
        return OtaChannel::query()->findOrFail($this->integer('ota_channel_id'));
    }

    /**
     * The typed name, kept only for the Other channel.
     */
    public function channelName(): ?string
    {
        return $this->isOther() ? $this->string('channel_name')->toString() : null;
    }

    public function login(): OtaCredentialLoginData
    {
        return new OtaCredentialLoginData(
            propertyCode: $this->string('property_code')->toString(),
            username: $this->string('username')->toString(),
            password: blank($this->input('password')) ? null : $this->string('password')->toString(),
            marketManagerName: $this->filled('market_manager_name') ? $this->string('market_manager_name')->toString() : null,
            marketManagerPhone: $this->filled('market_manager_phone') ? $this->string('market_manager_phone')->toString() : null,
            marketManagerEmail: $this->filled('market_manager_email') ? $this->string('market_manager_email')->toString() : null,
        );
    }

    private function isAdding(): bool
    {
        return $this->route('credential') === null;
    }

    private function isOther(): bool
    {
        return OtaChannel::query()->whereKey($this->integer('ota_channel_id'))->where('is_other', true)->exists();
    }
}
