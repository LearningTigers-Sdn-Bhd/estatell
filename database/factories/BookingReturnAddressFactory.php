<?php

namespace Database\Factories;

use App\Models\BookingReturnAddress;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingReturnAddress>
 */
class BookingReturnAddressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'url' => 'https://www.'.fake()->unique()->domainWord().'.com/booking/done',
        ];
    }
}
