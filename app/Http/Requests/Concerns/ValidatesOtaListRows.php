<?php

namespace App\Http\Requests\Concerns;

use App\Data\Onboarding\OnboardingOtaCredentialData;
use App\Models\OtaChannel;
use App\Models\Property;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * The rows of the OTA lists table, shared by onboarding and Settings → OTA lists. A channel must be active, or the
 * channel the saved row already uses. Other needs a channel name. One row per channel, and one Other row per name
 * ignoring case. A new row needs a password, a saved row may leave it blank to keep the saved one. A row with nothing
 * filled in is dropped.
 */
trait ValidatesOtaListRows
{
    public const MAX_ROWS = 20;

    private ?int $otherChannelId = null;

    protected function prepareForValidation(): void
    {
        $rows = $this->input('rows');

        if (! is_array($rows)) {
            return;
        }

        $rows = array_map(
            fn (mixed $row): mixed => is_array($row)
                ? [
                    ...$row,
                    'id' => filled($row['id'] ?? null) ? $row['id'] : null,
                    'channel_name' => trim((string) ($row['channel_name'] ?? '')),
                    'property_code' => trim((string) ($row['property_code'] ?? '')),
                    'username' => trim((string) ($row['username'] ?? '')),
                    'market_manager_name' => trim((string) ($row['market_manager_name'] ?? '')),
                    'market_manager_phone' => trim((string) ($row['market_manager_phone'] ?? '')),
                    'market_manager_email' => Str::lower(trim((string) ($row['market_manager_email'] ?? ''))),
                ]
                : $row,
            $rows,
        );

        $this->merge(['rows' => array_values(array_filter(
            $rows,
            fn (mixed $row): bool => ! is_array($row) || $row['id'] !== null || collect($row)->except('id')->contains(fn (mixed $value): bool => filled($value)),
        ))]);
    }

    /**
     * One channel per row, and one Other row per channel name. Errors show on the row that repeats.
     */
    protected function checkOtaListRows(Validator $validator): void
    {
        $validator->after(function (Validator $afterValidator): void {
            $seenChannels = [];
            $seenIds = [];

            foreach ($this->rowsInput() as $index => $row) {
                if ($row['id'] !== null) {
                    if (isset($seenIds[$row['id']])) {
                        $afterValidator->errors()->add("rows.$index.id", __('This channel is in the list twice.'));
                    }

                    $seenIds[$row['id']] = true;
                }

                $isOther = $this->isOther($row);
                $key = $isOther ? 'other:'.Str::lower($row['channel_name']) : 'channel:'.($row['ota_channel_id'] ?? '');

                if (isset($seenChannels[$key])) {
                    $afterValidator->errors()->add(
                        $isOther ? "rows.$index.channel_name" : "rows.$index.ota_channel_id",
                        $isOther ? __('This channel name is already in the list.') : __('This channel is already in the list.'),
                    );
                }

                $seenChannels[$key] = true;
            }
        });
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function otaListRules(): array
    {
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        $rules = [
            'rows' => ['present', 'array', 'max:'.self::MAX_ROWS],
            'rows.*' => ['array'],
        ];

        foreach ($this->rowsInput() as $index => $row) {
            $isNew = $row['id'] === null;
            $usedChannelId = $isNew ? null : $property->otaCredentials()->whereKey($row['id'])->value('ota_channel_id');

            $rules["rows.$index.id"] = [
                'nullable', 'integer',
                Rule::exists('property_ota_credentials', 'id')->where('property_id', $property->id),
            ];
            $rules["rows.$index.ota_channel_id"] = [
                'required', 'integer',
                Rule::exists('ota_channels', 'id')->where(fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->when($usedChannelId !== null, fn (Builder $query) => $query->orWhere('id', $usedChannelId))),
            ];
            $rules["rows.$index.channel_name"] = $this->isOther($row)
                ? ['required', 'string', 'max:80']
                : ['nullable', 'string', 'max:80'];
            $rules["rows.$index.property_code"] = ['required', 'string', 'max:80'];
            $rules["rows.$index.username"] = ['required', 'string', 'max:255'];
            $rules["rows.$index.password"] = [$isNew ? 'required' : 'nullable', 'string', 'max:255'];
            $rules["rows.$index.market_manager_name"] = ['nullable', 'string', 'max:255'];
            $rules["rows.$index.market_manager_phone"] = ['nullable', 'string', 'max:40'];
            $rules["rows.$index.market_manager_email"] = ['nullable', 'email', 'max:255'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.*.id.exists' => __('This channel row is no longer saved. Reload the page.'),
            'rows.*.ota_channel_id.required' => __('Choose a channel.'),
            'rows.*.ota_channel_id.exists' => __('Choose one of the listed channels.'),
            'rows.*.channel_name.required' => __('Enter the channel name.'),
            'rows.*.property_code.required' => __('Enter your property ID on this channel.'),
            'rows.*.username.required' => __('Enter a username.'),
            'rows.*.password.required' => __('Enter a password.'),
            'rows.*.market_manager_email.email' => __('Enter a valid email.'),
        ];
    }

    /**
     * The rows as the Action takes them. Only valid after validation has passed.
     *
     * @return list<OnboardingOtaCredentialData>
     */
    public function rows(): array
    {
        return array_map(
            fn (array $row): OnboardingOtaCredentialData => new OnboardingOtaCredentialData(
                id: $row['id'] === null ? null : (int) $row['id'],
                otaChannelId: (int) $row['ota_channel_id'],
                channelName: $this->isOther($row) ? $row['channel_name'] : null,
                propertyCode: $row['property_code'],
                username: $row['username'],
                password: blank($row['password'] ?? null) ? null : $row['password'],
                marketManagerName: blank($row['market_manager_name'] ?? null) ? null : $row['market_manager_name'],
                marketManagerPhone: blank($row['market_manager_phone'] ?? null) ? null : $row['market_manager_phone'],
                marketManagerEmail: blank($row['market_manager_email'] ?? null) ? null : $row['market_manager_email'],
            ),
            $this->rowsInput(),
        );
    }

    /**
     * The prepared rows, as arrays with their validation keys.
     *
     * @return list<array<string, mixed>>
     */
    private function rowsInput(): array
    {
        $rows = $this->input('rows');

        return is_array($rows) ? array_values(array_filter($rows, is_array(...))) : [];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isOther(array $row): bool
    {
        $this->otherChannelId ??= OtaChannel::query()->where('is_other', true)->value('id');

        return $this->otherChannelId !== null && (int) ($row['ota_channel_id'] ?? 0) === $this->otherChannelId;
    }
}
