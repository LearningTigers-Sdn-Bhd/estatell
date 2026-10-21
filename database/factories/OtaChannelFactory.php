<?php

namespace Database\Factories;

use App\Models\OtaChannel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OtaChannel>
 */
class OtaChannelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'code' => Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString(),
            'name' => $name,
            'channex_code' => null,
            'sort_order' => fn (): int => ((int) OtaChannel::query()->max('sort_order')) + 1,
            'is_active' => true,
            'is_other' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * The locked Other row. Its code is always `other`.
     */
    public function other(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => 'other',
            'name' => 'Other',
            'is_active' => true,
            'is_other' => true,
        ]);
    }
}
