<?php

namespace Database\Factories;

use App\Models\Guest;
use App\Models\GuestNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuestNote>
 */
class GuestNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guest_id' => Guest::factory(),
            'author_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
