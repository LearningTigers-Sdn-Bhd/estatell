<?php

namespace App\Http\Requests\Booking;

use App\Data\BookingEngine\NewBookingHoldData;
use App\Data\BookingEngine\NewBookingHoldLineData;
use App\Http\Requests\Guests\GuestProfileRules;
use App\Models\Property;
use App\Services\Guests\CountryOptions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The stay, the rooms, the guest and the total the guest saw. The token and reach middleware have already checked the
 * client. See docs/29-booking-engine/02-api.md#checkout.
 */
class StoreBookingHoldRequest extends FormRequest
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
        $guest = GuestProfileRules::for($this->phoneRegion());

        return [
            'arrival' => ['required', 'date_format:Y-m-d'],
            'departure' => ['required', 'date_format:Y-m-d', 'after:arrival'],
            'total' => ['required', 'decimal:0,2', 'min:0'],
            'guest' => ['required', 'array'],
            'guest.name' => ['required', ...array_slice($guest['full_name'], 1)],
            'guest.email' => ['required', ...array_slice($guest['email'], 1)],
            'guest.phone' => ['required', ...array_slice($guest['phone'], 1)],
            'guest.nationality' => ['required', Rule::in(app(CountryOptions::class)->codes())],
            'special_requests' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.room_type_id' => ['required', 'integer'],
            'lines.*.rate_plan_id' => ['required', 'integer'],
            'lines.*.adults' => ['required', 'integer', 'min:1', 'max:50'],
            'lines.*.child_ages' => ['nullable', 'array', 'max:50'],
            'lines.*.child_ages.*' => ['integer', 'min:0', 'max:17'],
        ];
    }

    public function holdData(): NewBookingHoldData
    {
        $phone = GuestProfileRules::attributes(['phone' => $this->string('guest.phone')->toString()], $this->phoneRegion())['phone'];

        return new NewBookingHoldData(
            CarbonImmutable::createFromFormat('!Y-m-d', $this->string('arrival')->toString()),
            CarbonImmutable::createFromFormat('!Y-m-d', $this->string('departure')->toString()),
            (string) $this->input('total'),
            $this->string('guest.name')->toString(),
            $this->string('guest.email')->toString(),
            $phone,
            $this->string('guest.nationality')->toString(),
            $this->filled('special_requests') ? $this->string('special_requests')->toString() : null,
            array_map(fn (array $line): NewBookingHoldLineData => new NewBookingHoldLineData(
                (int) $line['room_type_id'],
                (int) $line['rate_plan_id'],
                (int) $line['adults'],
                array_values(array_map(intval(...), $line['child_ages'] ?? [])),
            ), array_values($this->input('lines'))),
            $this->header('X-Guest-IP'),
        );
    }

    private function phoneRegion(): string
    {
        /** @var Property $property */
        $property = $this->attributes->get('booking_property');

        return (string) $property->country_code;
    }
}
