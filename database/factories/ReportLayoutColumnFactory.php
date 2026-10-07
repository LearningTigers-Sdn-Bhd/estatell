<?php

namespace Database\Factories;

use App\Models\ReportLayout;
use App\Models\ReportLayoutColumn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportLayoutColumn>
 */
class ReportLayoutColumnFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_layout_id' => ReportLayout::factory(),
            'column_key' => 'reservation',
            'position' => 0,
            'is_visible' => true,
        ];
    }
}
