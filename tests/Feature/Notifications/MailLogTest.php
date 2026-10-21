<?php

use App\Actions\Notifications\PruneMailEvents;
use App\Enums\MailStatus;
use App\Models\MailEvent;
use App\Models\Property;
use App\Models\User;
use App\Notifications\GoLiveApproved;
use App\Services\Notifications\MillionSendTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

function mailLogEmail(string $to = 'guest@example.test'): Email
{
    return (new Email)->from('noreply@estatell.test')->to($to)->subject('Hello guest')->text('Hi');
}

function mailLogMillionSendMailer(): void
{
    config([
        'mail.mailers.millionsend' => ['transport' => 'millionsend'],
        'services.millionsend.base_url' => 'https://mail.example.test',
        'services.millionsend.api_key' => 'ms_key',
    ]);
}

it('logs a sent message with the provider id and keeps the recipient encrypted', function () {
    Http::fake(['*' => Http::response(['id' => 'prov-123'], 200)]);
    mailLogMillionSendMailer();

    Mail::mailer('millionsend')->raw('plain', fn ($message) => $message->to('Guest@Example.test')->subject('Hello guest'));

    $event = MailEvent::query()->sole();

    expect($event->status)->toBe(MailStatus::Sent)
        ->and($event->recipient)->toBe('Guest@Example.test')
        ->and($event->subject)->toBe('Hello guest')
        ->and($event->provider_message_id)->toBe('prov-123')
        ->and(DB::table('mail_events')->value('recipient'))->not->toContain('Example.test');
});

it('logs the notification type and the staff property', function () {
    Http::fake(['*' => Http::response(['id' => 'prov-1'], 200)]);
    mailLogMillionSendMailer();
    $property = Property::factory()->create();
    $user = User::factory()->for($property)->create();

    $notification = new GoLiveApproved('Demo Hotel', 'demo-hotel');
    $user->notifyNow($notification, ['mail']);

    $event = MailEvent::query()->sole();

    expect($event->notification_type)->toBe('GoLiveApproved')
        ->and($event->property_id)->toBe($property->id);
});

it('logs a suppressed send once, as suppressed', function () {
    Http::fake(['*' => Http::response(['name' => 'all_recipients_suppressed'], 422)]);
    mailLogMillionSendMailer();

    Mail::mailer('millionsend')->raw('Hi', fn ($message) => $message->to('gone@example.test')->subject('Bounced before'));

    expect(MailEvent::query()->sole()->status)->toBe(MailStatus::Suppressed);
});

it('logs a failed send with the error and still throws', function () {
    Http::fake(['*' => Http::response(['name' => 'validation_error'], 422)]);

    expect(fn () => (new MillionSendTransport('https://mail.example.test', 'ms_key'))->send(mailLogEmail()))
        ->toThrow(TransportException::class);

    $event = MailEvent::query()->sole();

    expect($event->status)->toBe(MailStatus::Failed)
        ->and($event->error)->toContain('MillionSend 422')
        ->and($event->provider_message_id)->toBeNull();
});

it('prunes only rows older than 90 days with their blind index', function () {
    $old = MailEvent::factory()->create(['sent_at' => now()->subDays(91)]);
    $recent = MailEvent::factory()->create(['sent_at' => now()->subDays(89)]);

    expect(app(PruneMailEvents::class)())->toBe(1)
        ->and(MailEvent::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(MailEvent::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(DB::table('blind_indexes')->where('indexable_type', MailEvent::class)->where('indexable_id', $old->id)->exists())->toBeFalse()
        ->and(app(PruneMailEvents::class)())->toBe(0);
});

it('lists the mail log for admins with search, status and date filters', function () {
    $admin = User::factory()->admin()->create();
    MailEvent::factory()->create(['recipient' => 'Amir@Example.test', 'subject' => 'Welcome Amir']);
    MailEvent::factory()->failed()->create(['recipient' => 'other@example.test', 'subject' => 'Invoice']);
    MailEvent::factory()->create(['recipient' => 'old@example.test', 'subject' => 'Old news', 'sent_at' => now()->subDays(30)]);

    $recipients = fn (array $query) => $this->actingAs($admin)->get(route('admin.mail-events.index', $query))
        ->assertOk()
        ->viewData('page')['props']['events']['data'];

    expect($recipients([]))->toHaveCount(3)
        ->and(collect($recipients(['filter' => ['search' => 'amir@example.test']]))->pluck('recipient')->all())->toBe(['Amir@Example.test'])
        ->and(collect($recipients(['filter' => ['search' => 'Invoice']]))->pluck('recipient')->all())->toBe(['other@example.test'])
        ->and($recipients(['filter' => ['status' => 'failed']]))->toHaveCount(1)
        ->and($recipients(['filter' => ['from' => now()->subDays(1)->toDateString()]]))->toHaveCount(2);

    $this->actingAs($admin)->get(route('admin.mail-events.index'))->assertInertia(fn (Assert $page) => $page
        ->component('admin/mail-events/index')
        ->where('counts', ['sent' => 2, 'delivered' => 0, 'bounced' => 0, 'complained' => 0, 'failed' => 1, 'suppressed' => 0]));
});

it('shows one entry to admins only', function () {
    $event = MailEvent::factory()->failed()->create(['subject' => 'Invoice']);

    $this->actingAs(User::factory()->admin()->create())->get(route('admin.mail-events.show', $event))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/mail-events/show')->where('event.subject', 'Invoice'));

    $staff = User::factory()->for(Property::factory()->create())->create();

    $this->actingAs($staff)->get(route('admin.mail-events.index'))->assertNotFound();
    $this->actingAs($staff)->get(route('admin.mail-events.show', $event))->assertNotFound();
});
