<?php

namespace Database\Factories;

use App\Enums\NearbyPlaceStatus;
use App\Models\NearbyPlace;
use App\Services\BookingEngine\MapsPlace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NearbyPlace>
 */
class NearbyPlaceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);
        $latitude = fake()->randomFloat(7, 1, 7);
        $longitude = fake()->randomFloat(7, 100, 104);

        return [
            'name' => $name,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'google_maps_url' => 'https://www.google.com/maps/place/'.rawurlencode($name).'/@'.$latitude.','.$longitude.',17z',
            'fingerprint' => MapsPlace::fingerprintFor($name, (string) $latitude, (string) $longitude),
            'status' => NearbyPlaceStatus::Approved,
            'created_by_property_id' => null,
        ];
    }

    public function status(NearbyPlaceStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function at(float $latitude, float $longitude): static
    {
        return $this->state(fn (array $attributes): array => [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'fingerprint' => MapsPlace::fingerprintFor($attributes['name'], (string) $latitude, (string) $longitude),
        ]);
    }
}
