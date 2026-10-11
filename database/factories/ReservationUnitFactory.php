<?php

namespace Database\Factories;

use App\Enums\StayType;
use App\Enums\UnitStatus;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationUnit>
 */
class ReservationUnitFactory extends Factory
{
    /**
     * Define the model's default state: one adult, three nights from 2026-11-02, Confirmed. It has no nights until
     * `withNights()` is used.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'property_id' => fn (array $attributes): int => Reservation::query()->findOrFail($attributes['reservation_id'])->property_id,
            'room_type_id' => fn (array $attributes): int => RoomType::factory()->create(['property_id' => $attributes['property_id']])->id,
            'rate_plan_id' => fn (array $attributes): int => RatePlan::factory()->create(['property_id' => $attributes['property_id']])->id,
            'arrival_date' => '2026-11-02',
            'departure_date' => '2026-11-05',
            'arrival_time' => '15:00',
            'departure_time' => '11:00',
            'adults' => 1,
            'children' => 0,
            'status' => UnitStatus::Confirmed,
            'stay_type' => StayType::Overnight,
        ];
    }

    /**
     * A day use unit on `$date` from `$startsAt` for `$hours`, with its `unit_day_uses` row priced at 95.00 and no room.
     */
    public function dayUse(string $date = '2026-11-02', string $startsAt = '10:00', int $hours = 4, ?int $roomId = null, string $amount = '95.00'): static
    {
        $endsAt = sprintf('%02d:%s', (int) substr($startsAt, 0, 2) + $hours, substr($startsAt, 3, 2));

        return $this->state(fn (array $attributes) => [
            'stay_type' => StayType::DayUse,
            'arrival_date' => $date,
            'departure_date' => $date,
            'arrival_time' => $startsAt,
            'departure_time' => $endsAt,
        ])->afterCreating(function (ReservationUnit $unit) use ($date, $startsAt, $endsAt, $hours, $roomId, $amount): void {
            UnitDayUseFactory::new()->create([
                'reservation_unit_id' => $unit->id,
                'date' => $date,
                'room_id' => $roomId,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'hours' => $hours,
                'amount' => $amount,
            ]);
        });
    }

    public function checkedIn(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UnitStatus::CheckedIn,
            'checked_in_at' => now(),
        ]);
    }

    public function checkedOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UnitStatus::CheckedOut,
            'checked_in_at' => now()->subDay(),
            'checked_out_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UnitStatus::Cancelled]);
    }

    public function noShow(): static
    {
        return $this->state(fn (array $attributes) => ['status' => UnitStatus::NoShow]);
    }

    /**
     * Create one unit night for each night from arrival up to, not including, departure, priced at 100.00.
     */
    public function withNights(): static
    {
        return $this->afterCreating(function (ReservationUnit $unit): void {
            for ($date = $unit->arrival_date->toImmutable(); $date->lt($unit->departure_date); $date = $date->addDay()) {
                UnitNightFactory::new()->create([
                    'reservation_unit_id' => $unit->id,
                    'rate_plan_id' => $unit->rate_plan_id,
                    'date' => $date->toDateString(),
                ]);
            }
        });
    }
}
