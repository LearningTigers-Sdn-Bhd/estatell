<?php

namespace App\Http\Requests\Reservations;

use App\Enums\OldFolioMode;
use App\Enums\PermissionEnum;
use App\Enums\StayType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePastStayRequest extends StoreBookingRequest
{
    /**
     * The booking rules, plus a required reason. The `can:folio.manual` route middleware authorizes this request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'reason' => ['required', 'string', 'max:500'],
            'status' => ['nullable', Rule::in(['in_house', 'checked_out'])],
            'mode' => ['nullable', Rule::in(['past', 'migrated', 'old'])],
            'old_folio' => ['required_if:mode,migrated', 'nullable', Rule::enum(OldFolioMode::class)],
            'opening_amount' => ['nullable', 'string', 'max:20'],
            'legacy_number' => ['nullable', 'string', 'max:50'],
            'end_time' => ['required_if:stay_type,day_use', 'nullable', 'date_format:H:i'],
        ];
    }

    /**
     * The amount of a migrated stay may be typed as 1,080.00: the commas and spaces go before it is checked.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('opening_amount'))) {
            $this->merge(['opening_amount' => str_replace([',', ' '], '', trim($this->input('opening_amount')))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'reason.required' => __('Enter a reason.'),
            'old_folio.required_if' => __('Choose how to bring in the old folio.'),
        ];
    }

    /**
     * The booking checks, plus the rule that every unit of a past stay has a room or bed, whatever the source.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('stay_type') === StayType::DayUse->value) {
                $this->checkDayUse($validator);
            }

            foreach ($this->input('lines', []) as $index => $line) {
                if ($this->isBedLine((int) $line['room_type_id'])) {
                    foreach (range(0, max(1, (int) ($line['beds'] ?? 1)) - 1) as $bed) {
                        if (empty($line['bed_ids'][$bed])) {
                            $validator->errors()->add("lines.{$index}.bed_ids.{$bed}", __('Select a bed.'));
                        }
                    }
                } elseif (empty($line['room_id'])) {
                    $validator->errors()->add("lines.{$index}.room_id", __('Select a room.'));
                }
            }
        }];
    }

    /**
     * Whether the user may check in without a registration card. No card can exist before a past stay is saved.
     */
    public function withoutCard(): bool
    {
        return $this->user()->can(PermissionEnum::FrontDeskCheckInWithoutCard->value);
    }

    /**
     * Migrated stay mode: the stay began in the old system, before the go-live date.
     */
    public function isMigrated(): bool
    {
        return $this->validated('mode') === 'migrated';
    }

    /**
     * Old stay mode: the stay began and ended in the old system, before the go-live date. History only.
     */
    public function isOld(): bool
    {
        return $this->validated('mode') === 'old';
    }

    public function oldFolio(): OldFolioMode
    {
        return OldFolioMode::from((string) $this->validated('old_folio'));
    }

    /**
     * The signed amount of One amount as typed. An empty amount is zero, which posts nothing. `PostOpeningBalance`
     * refuses a value that is not an amount.
     */
    public function openingAmount(): string
    {
        return (string) $this->validated('opening_amount');
    }

    public function legacyNumber(): ?string
    {
        return $this->validated('legacy_number');
    }

    /**
     * Already checked out, as against Still in house (the default).
     */
    public function checkedOut(): bool
    {
        return $this->validated('status') === 'checked_out';
    }

    /**
     * The End of a day use, `H:i`: the moment the guest left on the stay's date. Null for a stay of nights.
     */
    public function endTime(): ?string
    {
        return $this->validated('end_time');
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }

    /**
     * A past day use has ended, so it is saved as already checked out, with an End after its Start.
     */
    private function checkDayUse(Validator $validator): void
    {
        if ($this->input('status') !== 'checked_out') {
            $validator->errors()->add('status', __('A past day use has ended. Save it as already checked out.'));
        } elseif ($this->input('end_time') <= $this->input('start_time')) {
            $validator->errors()->add('end_time', __('The end must be after the start.'));
        }
    }
}
