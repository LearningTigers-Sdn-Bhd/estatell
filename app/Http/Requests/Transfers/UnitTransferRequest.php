<?php

namespace App\Http\Requests\Transfers;

use App\Data\Transfers\UnitTransferInputData;
use App\Enums\TransferDirection;
use App\Enums\TransferMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Set one direction of a unit's land transfer from the reservation Transfers tab. */
class UnitTransferRequest extends FormRequest
{
    /**
     * The `can:reservations.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(TransferMode::class)],
            'transfer_route_id' => ['required', 'integer'],
            'pickup_time' => ['required', 'date_format:H:i'],
            'flight_number' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9 ]+$/'],
            'note' => ['nullable', 'string', 'max:200'],
            'bags' => ['nullable', 'integer', 'min:0', 'max:99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['flight_number.regex' => __('Use letters and numbers only, such as AK 6231.')];
    }

    public function toInputData(TransferDirection $direction): UnitTransferInputData
    {
        return new UnitTransferInputData(
            direction: $direction,
            mode: TransferMode::from((string) $this->validated('mode')),
            routeId: (int) $this->validated('transfer_route_id'),
            pickupTime: (string) $this->validated('pickup_time'),
            flightNumber: $this->validated('flight_number'),
            note: $this->validated('note'),
            bags: $this->validated('bags') === null ? null : (int) $this->validated('bags'),
        );
    }
}
