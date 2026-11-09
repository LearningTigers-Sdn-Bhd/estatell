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
 * The onboarding OTA lists table: `saved_ids` are the saved rows to keep, and `rows` are the new ones. A channel must be
 * active. Other needs a channel name. One row per channel, and one Other row per name ignoring case, counting the saved
 * rows that stay. A row with nothing filled in is dropped.
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
            fn (mixed $row): bool => ! is_array($row) || collect($row)->contains(fn (mixed $value): bool => filled($value)),
        ))]);
    }

    /**
     * One channel per row, and one Other row per channel name. Errors show on the row that repeats.
     */
    protected function checkOtaListRows(Validator $validator): void
    {
        $validator->after(function (Validator $afterValidator): void {
            $seenChannels = [];

            foreach ($this->otaListProperty()->otaCredentials()->whereKey($this->savedIds())->get(['ota_channel_id', 'channel_name']) as $saved) {
                $seenChannels[$saved->channel_name === null ? 'channel:'.$saved->ota_channel_id : 'other:'.Str::lower($saved->channel_name)] = true;
            }

            foreach ($this->rowsInput() as $index => $row) {
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
        $rules = [
            'saved_ids' => ['present', 'array'],
            'saved_ids.*' => [
                'integer', 'distinct',
                Rule::exists('property_ota_credentials', 'id')->where('property_id', $this->otaListProperty()->id),
            ],
            'rows' => ['present', 'array', 'max:'.max(0, self::MAX_ROWS - count($this->savedIds()))],
            'rows.*' => ['array'],
        ];

        foreach ($this->rowsInput() as $index => $row) {
            $rules["rows.$index.ota_channel_id"] = [
                'required', 'integer',
                Rule::exists('ota_channels', 'id')->where(fn (Builder $query) => $query->where('is_active', true)),
            ];
            $rules["rows.$index.channel_name"] = $this->isOther($row)
                ? ['required', 'string', 'max:80']
                : ['nullable', 'string', 'max:80'];
            $rules["rows.$index.property_code"] = ['required', 'string', 'max:80'];
            $rules["rows.$index.username"] = ['required', 'string', 'max:255'];
            $rules["rows.$index.password"] = ['required', 'string', 'max:255'];
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
            'saved_ids.*.exists' => __('A saved channel is no longer there. Reload the page.'),
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
     * The new rows as the Action takes them. Only valid after validation has passed.
     *
     * @return list<OnboardingOtaCredentialData>
     */
    public function rows(): array
    {
        return array_map(
            fn (array $row): OnboardingOtaCredentialData => new OnboardingOtaCredentialData(
                otaChannelId: (int) $row['ota_channel_id'],
                channelName: $this->isOther($row) ? $row['channel_name'] : null,
                propertyCode: $row['property_code'],
                username: $row['username'],
                password: $row['password'],
                marketManagerName: blank($row['market_manager_name'] ?? null) ? null : $row['market_manager_name'],
                marketManagerPhone: blank($row['market_manager_phone'] ?? null) ? null : $row['market_manager_phone'],
                marketManagerEmail: blank($row['market_manager_email'] ?? null) ? null : $row['market_manager_email'],
            ),
            $this->rowsInput(),
        );
    }

    /**
     * The ids of the saved rows to keep. Anything that is not a whole number is left for the rules to refuse.
     *
     * @return list<int>
     */
    public function savedIds(): array
    {
        $ids = $this->input('saved_ids');

        return is_array($ids) ? array_values(array_map(intval(...), array_filter($ids, is_numeric(...)))) : [];
    }

    private function otaListProperty(): Property
    {
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return $property;
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
