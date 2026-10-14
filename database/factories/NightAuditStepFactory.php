<?php

namespace Database\Factories;

use App\Enums\AuditStep;
use App\Enums\AuditStepStatus;
use App\Models\NightAudit;
use App\Models\NightAuditStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NightAuditStep>
 */
class NightAuditStepFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => fn (array $attributes) => NightAudit::query()->findOrFail((int) $attributes['night_audit_id'])->property_id,
            'night_audit_id' => NightAudit::factory(),
            'step' => AuditStep::PostRoomCharges,
            'status' => AuditStepStatus::Completed,
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
