<?php

namespace Database\Seeders;

use App\Models\OtaChannel;
use Illuminate\Database\Seeder;

class OtaChannelSeeder extends Seeder
{
    /**
     * Seed the starting OTA channels and the locked Other row. Safe to run again: each row is matched on its code.
     */
    public function run(): void
    {
        $channels = [
            ['code' => 'agoda', 'name' => 'Agoda', 'is_other' => false],
            ['code' => 'booking_com', 'name' => 'Booking.com', 'is_other' => false],
            ['code' => 'hostelworld', 'name' => 'Hostelworld', 'is_other' => false],
            ['code' => 'expedia', 'name' => 'Expedia', 'is_other' => false],
            ['code' => 'airbnb', 'name' => 'Airbnb', 'is_other' => false],
            ['code' => 'other', 'name' => 'Other', 'is_other' => true],
        ];

        foreach ($channels as $position => $channel) {
            OtaChannel::query()->updateOrCreate(
                ['code' => $channel['code']],
                ['name' => $channel['name'], 'sort_order' => $position + 1, 'is_other' => $channel['is_other']],
            );
        }
    }
}
