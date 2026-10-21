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
            ['code' => 'agoda', 'channex_code' => 'Agoda', 'name' => 'Agoda', 'is_other' => false],
            ['code' => 'booking_com', 'channex_code' => 'BookingCom', 'name' => 'Booking.com', 'is_other' => false],
            ['code' => 'hostelworld', 'channex_code' => 'Hostelworld', 'name' => 'Hostelworld', 'is_other' => false],
            ['code' => 'expedia', 'channex_code' => 'Expedia', 'name' => 'Expedia', 'is_other' => false],
            ['code' => 'airbnb', 'channex_code' => 'AirBNB', 'name' => 'Airbnb', 'is_other' => false],
            ['code' => 'other', 'channex_code' => null, 'name' => 'Other', 'is_other' => true],
        ];

        foreach ($channels as $position => $channel) {
            OtaChannel::query()->updateOrCreate(
                ['code' => $channel['code']],
                ['name' => $channel['name'], 'channex_code' => $channel['channex_code'], 'sort_order' => $position + 1, 'is_other' => $channel['is_other']],
            );
        }
    }
}
