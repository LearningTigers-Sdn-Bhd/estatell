<?php

namespace App\Http\Requests\Booking;

use App\Data\BookingEngine\NewBookingHoldLineData;
use App\Services\Guests\CountryOptions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The stay and the rooms a guest chose. The token and reach middleware have already checked the client. See
 * docs/29-booking-engine/02-api.md#pricing.
 */
class PriceBookingBasketRequest extends FormRequest
{
    private const int MAX_LINES = 50;

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
            'arrival' => ['required', 'date_format:Y-m-d'],
            'departure' => ['required', 'date_format:Y-m-d', 'after:arrival'],
            'nationality' => ['nullable', Rule::in(app(CountryOptions::class)->codes())],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.room_type_id' => ['required', 'integer'],
            'lines.*.rate_plan_id' => ['required', 'integer'],
            'lines.*.adults' => ['required', 'integer', 'min:1', 'max:50'],
            'lines.*.child_ages' => ['nullable', 'array', 'max:50'],
            'lines.*.child_ages.*' => ['integer', 'min:0', 'max:17'],
        ];
    }

    public function arrival(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('arrival')->toString());
    }

    public function departure(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('departure')->toString());
    }

    /**
     * @return list<NewBookingHoldLineData>
     */
    public function lines(): array
    {
        return array_map(fn (array $line): NewBookingHoldLineData => new NewBookingHoldLineData(
            (int) $line['room_type_id'],
            (int) $line['rate_plan_id'],
            (int) $line['adults'],
            array_values(array_map(intval(...), $line['child_ages'] ?? [])),
        ), array_values($this->input('lines')));
    }
}
