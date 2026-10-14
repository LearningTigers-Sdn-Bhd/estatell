<?php

namespace Database\Factories;

use App\Enums\AuditStatus;
use App\Enums\AuditTrigger;
use App\Models\BusinessDate;
use App\Models\NightAudit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NightAudit>
 */
class NightAuditFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => fn (array $attributes) => BusinessDate::query()->findOrFail((int) $attributes['business_date_id'])->property_id,
            'business_date_id' => BusinessDate::factory()->auditing(),
            'trigger' => AuditTrigger::Manual,
            'status' => AuditStatus::Running,
            'started_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => AuditStatus::Failed]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => AuditStatus::Completed, 'completed_at' => now()]);
    }
}
