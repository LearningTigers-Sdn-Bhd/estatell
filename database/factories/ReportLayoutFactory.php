<?php

namespace Database\Factories;

use App\Enums\PageOrientation;
use App\Enums\ReportKey;
use App\Models\Property;
use App\Models\ReportLayout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportLayout>
 */
class ReportLayoutFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'report_key' => ReportKey::Arrivals,
            'orientation' => PageOrientation::Portrait,
        ];
    }

    public function landscape(): static
    {
        return $this->state(['orientation' => PageOrientation::Landscape]);
    }
}
