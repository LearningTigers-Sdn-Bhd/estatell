<?php

namespace Database\Factories;

use App\Enums\RevisionKind;
use App\Models\ChannelBookingRevision;
use App\Models\ChannelProperty;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChannelBookingRevision>
 */
class ChannelBookingRevisionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_property_id' => ChannelProperty::factory(),
            'external_booking_id' => (string) Str::uuid(),
            'revision_id' => (string) Str::uuid(),
            'kind' => RevisionKind::New,
            'payload' => [],
        ];
    }
}
