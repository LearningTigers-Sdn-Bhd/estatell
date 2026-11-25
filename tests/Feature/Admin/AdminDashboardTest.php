<?php

use App\Enums\ChannelConnectionStatus;
use App\Enums\CommissionEntryType;
use App\Enums\CommissionService;
use App\Enums\ContactEnquiryStatus;
use App\Enums\MailStatus;
use App\Enums\PropertyStatus;
use App\Enums\SignInResult;
use App\Models\BusinessDate;
use App\Models\ChannelConnection;
use App\Models\ChannelProperty;
use App\Models\CommissionEntry;
use App\Models\ContactEnquiry;
use App\Models\MailEvent;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\SignInEvent;
use App\Models\User;
use App\Models\WebhookDelivery;
use Inertia\Testing\AssertableInertia as Assert;

test('staff cannot open the admin dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.dashboard'))
        ->assertNotFound();
});

test('the to do lists what needs an admin and links to the filtered list', function () {
    Property::factory()->onboarding()->create(['status' => PropertyStatus::PendingApproval, 'name' => 'Waiting Inn']);
    ContactEnquiry::factory()->count(2)->create();
    ContactEnquiry::factory()->create(['status' => ContactEnquiryStatus::Closed]);

    $broken = Property::factory()->create(['name' => 'Broken Lodge']);
    ChannelConnection::factory()->count(2)->create([
        'channel_property_id' => ChannelProperty::factory()->create(['property_id' => $broken->id])->id,
        'status' => ChannelConnectionStatus::Error,
    ]);

    WebhookDelivery::factory()->failed()->create();

    MailEvent::factory()->create(['status' => MailStatus::Bounced, 'sent_at' => '2026-10-08 00:00:00']);
    MailEvent::factory()->create(['status' => MailStatus::Bounced, 'sent_at' => '2026-10-07 23:59:59']);

    // The frozen clock is 2026-10-14 20:00 in Malaysia, so "since yesterday" starts at 2026-10-12 16:00 UTC.
    SignInEvent::factory()->create(['result' => SignInResult::LockedOut, 'occurred_at' => '2026-10-12 16:00:00']);
    SignInEvent::factory()->create(['result' => SignInResult::LockedOut, 'occurred_at' => '2026-10-12 15:59:59']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')
            ->missing('todo')
            ->loadDeferredProps('todo', fn (Assert $page) => $page
                ->where('todo.total', 8)
                ->has('todo.rows', 6)
                ->where('todo.rows.0.key', 'pending-approval')
                ->where('todo.rows.0.label', 'property waits for approval')
                ->where('todo.rows.0.href', '/admin/properties?filter%5Bstatus%5D=pending_approval')
                ->where('todo.rows.1.key', 'enquiries')
                ->where('todo.rows.1.count', 2)
                ->where('todo.rows.2.key', "channels-{$broken->code}")
                ->where('todo.rows.2.count', 2)
                ->where('todo.rows.2.detail', 'Broken Lodge')
                ->where('todo.rows.2.href', "/admin/properties/{$broken->code}/channels")
                ->where('todo.rows.3.key', 'webhooks-failed')
                ->where('todo.rows.4.key', 'mail-bounced')
                ->where('todo.rows.4.count', 1)
                ->where('todo.rows.4.href', '/admin/mail-log?filter%5Bstatus%5D=bounced&filter%5Bfrom%5D=2026-10-08')
                ->where('todo.rows.5.key', 'lockouts')
                ->where('todo.rows.5.count', 1)
                ->where('todo.rows.5.href', '/admin/sign-in-log?filter%5Bresult%5D=locked_out&filter%5Bfrom%5D=2026-10-13')
            )
        );
});

test('the to do is empty when nothing needs an admin', function () {
    Property::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps('todo', fn (Assert $page) => $page
                ->where('todo.total', 0)
                ->has('todo.rows', 0)
            )
        );
});

test('the summary counts properties and lists the latest properties', function () {
    $this->travelTo('2026-09-20 00:00:00');
    $old = Property::factory()->create(['name' => 'Old Live']);

    $this->travelTo('2026-10-02 00:00:00');
    $signedUp = Property::factory()->onboarding()->create(['name' => 'New Signup']);
    Property::factory()->suspended()->create();

    $this->travelTo('2026-10-14 12:00:00');
    $old->update(['went_live_at' => now()]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps('summary', fn (Assert $page) => $page
                ->where('summary.live', 1)
                ->where('summary.onboarding', 1)
                ->where('summary.suspended', 1)
                ->where('summary.pendingApproval', 0)
                ->has('summary.commissions.immediatePayable')
                ->has('summary.recent', 3)
                ->where('summary.recent.0.code', $old->code)
                ->where('summary.recent.0.event', 'Went live')
                ->where('summary.recent.0.at', '2026-10-14')
                ->where('summary.recent.2.code', $signedUp->code)
                ->where('summary.recent.2.event', 'Signed up')
            )
        );
});

test('the to do flags a late night audit, quiet properties and stalled onboarding', function () {
    $late = Property::factory()->create(['name' => 'Late Audit Inn']);
    BusinessDate::factory()->create(['property_id' => $late->id, 'date' => '2026-10-11']);
    $onTime = Property::factory()->create();
    BusinessDate::factory()->create(['property_id' => $onTime->id, 'date' => '2026-10-13']);

    $this->travelTo('2026-09-01 00:00:00');
    $quiet = Property::factory()->create(['name' => 'Quiet Lodge']);
    $busy = Property::factory()->create();
    $this->travelBack();
    $this->travelTo('2026-10-14 12:00:00');
    Reservation::factory()->create(['property_id' => $busy->id]);

    Property::factory()->onboarding()->create(['onboarding_last_activity_at' => '2026-09-29 00:00:00']);
    Property::factory()->onboarding()->create(['onboarding_last_activity_at' => '2026-10-10 00:00:00']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps('todo', fn (Assert $page) => $page
                ->where('todo.total', 3)
                ->has('todo.rows', 3)
                ->where('todo.rows.0.key', "night-audit-{$late->code}")
                ->where('todo.rows.0.label', 'Night audit 3 days behind')
                ->where('todo.rows.0.href', "/admin/properties/{$late->code}")
                ->where('todo.rows.1.key', "quiet-{$quiet->code}")
                ->where('todo.rows.1.detail', 'Quiet Lodge')
                ->where('todo.rows.2.key', 'stuck-onboarding')
                ->where('todo.rows.2.count', 1)
                ->where('todo.rows.2.href', '/admin/properties?filter%5Bstatus%5D=onboarding')
            )
        );
});

test('the monthly charts count sign-ups, go-lives and commission income by service', function () {
    $commission = fn (CommissionService $service, string $amount): CommissionEntry => CommissionEntry::factory()->create([
        'type' => CommissionEntryType::Commission,
        'reservation_id' => Reservation::factory()->create()->id,
        'service' => $service,
        'base_amount' => '100.00',
        'rate' => '10.00',
        'amount' => $amount,
        'reason' => null,
    ]);

    $this->travelTo('2026-04-20 00:00:00');
    $commission(CommissionService::BookingEngine, '-50.00');

    $this->travelTo('2026-09-20 00:00:00');
    Property::factory()->onboarding()->create();

    $this->travelTo('2026-10-05 00:00:00');
    $commission(CommissionService::BookingEngine, '-12.50');
    $commission(CommissionService::ChannelManager, '-3.00');

    $this->travelTo('2026-10-14 12:00:00');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps('summary', fn (Assert $page) => $page
                ->has('summary.months', 6)
                ->where('summary.months.0.month', '2026-05')
                ->where('summary.months.0.bookingEngine', '0.00')
                ->where('summary.months.4.month', '2026-09')
                ->where('summary.months.4.signUps', 1)
                ->where('summary.months.4.goLives', 0)
                ->where('summary.months.5.month', '2026-10')
                ->where('summary.months.5.bookingEngine', '12.50')
                ->where('summary.months.5.channelManager', '3.00')
            )
        );
});
