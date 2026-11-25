<?php

namespace Database\Factories;

use App\Enums\PosOrderSource;
use App\Enums\PosOrderStatus;
use App\Enums\PosOrderType;
use App\Models\PosDiningOption;
use App\Models\PosOrder;
use App\Models\PosOutlet;
use App\Models\PosShift;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PosOrder>
 */
class PosOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'pos_outlet_id' => fn (array $attributes): int => PosOutlet::factory()->create(['property_id' => $attributes['property_id']])->id,
            'pos_shift_id' => fn (array $attributes): int => PosShift::factory()->create(['property_id' => $attributes['property_id'], 'pos_outlet_id' => $attributes['pos_outlet_id']])->id,
            'client_uuid' => (string) Str::uuid(),
            'order_type' => PosOrderType::Sale,
            'pos_dining_option_id' => fn (array $attributes): int => PosDiningOption::factory()->create(['property_id' => $attributes['property_id'], 'pos_outlet_id' => $attributes['pos_outlet_id']])->id,
            'guest_count' => 1,
            'source' => PosOrderSource::Terminal,
            'status' => PosOrderStatus::Open,
            'opened_at' => now(),
        ];
    }
}
