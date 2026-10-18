<?php

namespace Database\Factories;

use App\Enums\BookingSourceIcon;
use App\Enums\BookingSourceType;
use App\Enums\SwatchColor;
use App\Models\BookingSource;
use App\Models\OtaChannel;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingSource>
 */
class BookingSourceFactory extends Factory
{
    /**
     * Define the model's default state: an active Direct source with the Globe badge.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => fake()->unique()->words(2, true),
            'type' => BookingSourceType::Direct,
            'ota_channel_id' => null,
            'is_system' => false,
            'is_active' => true,
            'sort_order' => 1,
            'badge_icon' => BookingSourceIcon::Globe,
            'badge_letters' => null,
            'badge_color' => SwatchColor::Gray,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * An OTA source on a channel (Booking.com unless one is given), badged with its first letter.
     */
    public function ota(?OtaChannel $channel = null, ?string $name = null): static
    {
        return $this->state(function (array $attributes) use ($channel, $name): array {
            $channel ??= OtaChannel::query()->firstOrCreate(['code' => 'booking_com'], ['name' => 'Booking.com', 'sort_order' => 2, 'is_other' => false]);
            $name ??= $channel->name;

            return [
                'name' => $name,
                'type' => BookingSourceType::Ota,
                'ota_channel_id' => $channel->id,
                'badge_icon' => null,
                'badge_letters' => strtoupper(substr($name, 0, 1)),
                'badge_color' => SwatchColor::forOtaChannelCode($channel->code),
            ];
        });
    }

    /**
     * The property's system Company / Travel agent row.
     */
    public function company(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Company / Travel agent',
            'type' => BookingSourceType::Company,
            'is_system' => true,
            'sort_order' => 1000,
            'badge_icon' => BookingSourceIcon::BriefcaseBusiness,
            'badge_color' => SwatchColor::Amber,
        ]);
    }

    /**
     * The property's system Walk-in row.
     */
    public function walkIn(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Walk-in',
            'type' => BookingSourceType::WalkIn,
            'is_system' => true,
            'sort_order' => 1001,
            'badge_icon' => BookingSourceIcon::Footprints,
            'badge_color' => SwatchColor::Teal,
        ]);
    }

    /**
     * The property's source of a system type, made once: the partial unique index allows one system row per type.
     */
    public static function systemFor(int $propertyId, BookingSourceType $type): BookingSource
    {
        return BookingSource::query()->where('property_id', $propertyId)->where('type', $type)->where('is_system', true)->first()
            ?? BookingSource::factory()->{$type === BookingSourceType::Company ? 'company' : 'walkIn'}()->create(['property_id' => $propertyId]);
    }

    /**
     * The property's "Direct" source, made once, so many reservations of one property share it.
     */
    public static function directFor(int $propertyId): BookingSource
    {
        return BookingSource::query()->where('property_id', $propertyId)->where('type', BookingSourceType::Direct)->where('name', 'Direct')->first()
            ?? BookingSource::factory()->create(['property_id' => $propertyId, 'name' => 'Direct']);
    }

    /**
     * The property's OTA source for a channel, made once.
     */
    public static function otaFor(int $propertyId, string $name = 'Booking.com'): BookingSource
    {
        return BookingSource::query()->where('property_id', $propertyId)->where('type', BookingSourceType::Ota)->where('name', $name)->first()
            ?? BookingSource::factory()->ota(null, $name)->create(['property_id' => $propertyId]);
    }
}
