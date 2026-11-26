<?php

namespace App\Http\Requests\Transfers;

use App\Data\Transfers\UnitTransferInputData;
use App\Enums\TransferDirection;
use App\Enums\TransferMode;
use Illuminate\Validation\Rule;

/**
 * The `transfer_in` and `transfer_out` picks of a booking (the booking form and the company portal): the shape the
 * reservation Transfers tab checks, and the input data for every room. `SetUnitTransfer` checks the route, its direction
 * and its price. See docs/35-transfers/01-land-transfers.md#booking-form.
 */
class TransferPickRules
{
    /**
     * @return array<string, array<mixed>>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach (['transfer_in', 'transfer_out'] as $field) {
            $rules += [
                $field => ['prohibited_if:stay_type,day_use', 'nullable', 'array'],
                $field.'.mode' => ['required_with:'.$field, Rule::enum(TransferMode::class)],
                $field.'.transfer_route_id' => ['required_with:'.$field, 'integer'],
                $field.'.pickup_time' => ['required_with:'.$field, 'date_format:H:i'],
                $field.'.flight_number' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9 ]+$/'],
                $field.'.bags' => ['nullable', 'integer', 'min:0', 'max:99'],
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $messages = [];

        foreach (['transfer_in', 'transfer_out'] as $field) {
            $messages += [
                $field.'.flight_number.regex' => __('Use letters and numbers only, such as AK 6231.'),
                $field.'.transfer_route_id.required_with' => __('Pick a route.'),
                $field.'.pickup_time.required_with' => __('Enter the pick-up time.'),
            ];
        }

        return $messages;
    }

    /**
     * The validated picks as input data, Transfer in first.
     *
     * @param  array<string, mixed>  $validated
     * @return list<UnitTransferInputData>
     */
    public static function inputs(array $validated): array
    {
        $transfers = [];

        foreach ([['transfer_in', TransferDirection::In], ['transfer_out', TransferDirection::Out]] as [$field, $direction]) {
            $pick = $validated[$field] ?? null;

            if (is_array($pick)) {
                $transfers[] = new UnitTransferInputData(
                    direction: $direction,
                    mode: TransferMode::from((string) $pick['mode']),
                    routeId: (int) $pick['transfer_route_id'],
                    pickupTime: (string) $pick['pickup_time'],
                    flightNumber: $pick['flight_number'] ?? null,
                    bags: isset($pick['bags']) ? (int) $pick['bags'] : null,
                );
            }
        }

        return $transfers;
    }
}
