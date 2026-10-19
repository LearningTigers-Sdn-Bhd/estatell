<?php

namespace App\Http\Requests\Reservations;

use App\Enums\PermissionEnum;
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
            'departure_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'reason.required' => __('Enter a reason.'),
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

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}
