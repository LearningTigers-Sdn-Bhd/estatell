<?php

use App\Actions\DoorLocks\RetryStayPasscodes;
use App\Actions\Kiosk\IssueBookingPreCheckInToken;
use App\Features\Concierge;
use App\Jobs\SyncStayPasscodesJob;
use App\Models\ReservationUnit;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/ConciergeSetup.php';
require_once __DIR__.'/../Support/DoorLockSetup.php';

/**
 * A checked-in stay whose room has a TTLock lock but no PIN row, which is how a stay looks when the gateway never answered.
 *
 * @return array{token: string, unit: ReservationUnit}
 */
function lockedConciergeStay(): array
{
    ['property' => $property, 'room' => $room] = doorLockSetup();
    $unit = doorLockStay($property, $room);
    // The room's lock is the one of tonight's night, and a PIN that is not there two minutes after check-in has failed.
    test()->travelTo('2026-10-14 12:00:00');
    $unit->forceFill(['checked_in_at' => now()->subHour()])->save();
    Feature::for($property)->activate(Concierge::class);
    conciergeCheckedIn($unit);

    return ['token' => basename(app(IssueBookingPreCheckInToken::class)($unit)), 'unit' => $unit];
}

/** The page shows the key only for a stay the Concierge page checked in just now, which is this record. */
function conciergeCheckedIn(ReservationUnit $unit): void
{
    activity()->performedOn($unit)->event('concierge.checked_in')->log('Checked in on the concierge page');
}

it('offers Try again for a PIN that failed on a stay with a lock, and shows waiting once it is asked for', function () {
    Queue::fake();
    ['token' => $token, 'unit' => $unit] = lockedConciergeStay();

    conciergeStep($token, 'key')->assertInertia(fn (Assert $page) => $page->where('stay.pin.status', 'failed')->where('stay.pin.canRetry', true));

    $this->post("/c/{$token}/pin/retry")->assertRedirect("/c/{$token}");

    Queue::assertPushed(SyncStayPasscodesJob::class, fn (SyncStayPasscodesJob $job) => $job->reservationUnitId === $unit->id);
    conciergeStep($token, 'key')->assertInertia(fn (Assert $page) => $page->where('stay.pin.status', 'pending')->where('stay.pin.canRetry', false));

    RetryStayPasscodes::finished($unit->id);
    conciergeStep($token, 'key')->assertInertia(fn (Assert $page) => $page->where('stay.pin.status', 'failed'));
});

it('does not offer Try again, and queues nothing, when the stay has no lock to make a PIN on', function () {
    Queue::fake();
    ['token' => $token, 'unit' => $unit] = conciergeInStaySetup();
    conciergeCheckedIn($unit);

    conciergeStep($token, 'key')->assertInertia(fn (Assert $page) => $page->where('stay.pin.status', 'failed')->where('stay.pin.canRetry', false));

    $this->post("/c/{$token}/pin/retry")->assertRedirect("/c/{$token}");

    Queue::assertNothingPushed();
});

it('allows three tries per stay in ten minutes', function () {
    Queue::fake();
    ['token' => $token, 'unit' => $unit] = lockedConciergeStay();
    RateLimiter::clear('pin-retry-limit:'.$unit->id);

    foreach (range(1, 4) as $try) {
        $this->post("/c/{$token}/pin/retry")->assertRedirect("/c/{$token}");
        RetryStayPasscodes::finished($unit->id);
    }

    // The fake queue keeps the job's unique lock, so count the limiter's hits: the fourth try was refused.
    expect(RateLimiter::attempts('pin-retry-limit:'.$unit->id))->toBe(3);
});
