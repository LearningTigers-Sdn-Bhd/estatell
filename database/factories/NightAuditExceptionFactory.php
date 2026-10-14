<?php

namespace Database\Factories;

use App\Enums\AuditExceptionType;
use App\Enums\AuditStep;
use App\Models\NightAudit;
use App\Models\NightAuditException;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NightAuditException>
 */
class NightAuditExceptionFactory extends Factory
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
            'type' => AuditExceptionType::RoomCharge,
            'message' => 'folio_not_open',
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => ['resolved_at' => now()]);
    }
}
