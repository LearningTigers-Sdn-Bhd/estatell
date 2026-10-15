<?php

use App\Models\OtaChannel;
use Database\Seeders\OtaChannelSeeder;

test('the seeder creates the starting channels and the locked Other row in order', function () {
    $this->seed(OtaChannelSeeder::class);

    expect(OtaChannel::query()->ordered()->get()->map(fn (OtaChannel $channel) => [
        $channel->code, $channel->name, $channel->sort_order, $channel->is_active, $channel->is_other,
    ])->all())->toBe([
        ['agoda', 'Agoda', 1, true, false],
        ['booking_com', 'Booking.com', 2, true, false],
        ['hostelworld', 'Hostelworld', 3, true, false],
        ['expedia', 'Expedia', 4, true, false],
        ['airbnb', 'Airbnb', 5, true, false],
        ['other', 'Other', 6, true, true],
    ]);
});

test('running the seeder again keeps six rows and leaves a deactivated channel off', function () {
    $this->seed(OtaChannelSeeder::class);
    OtaChannel::query()->where('code', 'expedia')->update(['is_active' => false]);

    $this->seed(OtaChannelSeeder::class);

    expect(OtaChannel::query()->count())->toBe(6)
        ->and(OtaChannel::query()->where('code', 'expedia')->value('is_active'))->toBeFalse();
});
