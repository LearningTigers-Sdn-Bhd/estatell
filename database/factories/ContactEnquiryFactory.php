<?php

namespace Database\Factories;

use App\Enums\ContactEnquiryStatus;
use App\Models\ContactEnquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactEnquiry>
 */
class ContactEnquiryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hotel_name' => fake()->company().' Hotel',
            'location' => fake()->city().', Sabah',
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+6012'.fake()->numerify('#######'),
            'room_count' => fake()->numberBetween(5, 120),
            'message' => fake()->sentence(12),
            'status' => ContactEnquiryStatus::New,
        ];
    }

    public function contacted(): static
    {
        return $this->state(['status' => ContactEnquiryStatus::Contacted]);
    }

    public function closed(): static
    {
        return $this->state(['status' => ContactEnquiryStatus::Closed]);
    }
}
