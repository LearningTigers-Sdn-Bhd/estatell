<?php

use App\Actions\Notifications\PruneMailEvents;
use App\Enums\AuditTrigger;
use App\Enums\MailActorType;
use App\Enums\MailStatus;
use App\Models\BusinessDate;
use App\Models\MailEvent;
use App\Models\NightAudit;
use App\Models\Property;
use App\Models\User;
use App\Notifications\GoLiveApproved;
use App\Services\Notifications\MailActorResolver;
use App\Services\Notifications\MillionSendTransport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
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

function mailLogSendRoute(): void
{
    Route::middleware('web')->get('/mail-log-test/{property:code}', function (Property $property) {
        Mail::raw('Hi', fn ($message) => $message->to('someone@example.test')->subject('Actor test'));

        return response()->noContent();
    });
}

it('records staff, partner, admin and company users who trigger a mail', function (Closure $makeUser, MailActorType $type) {
    mailLogSendRoute();
    $property = Property::factory()->create();
    $user = $makeUser($property);

    $this->actingAs($user)->get("/mail-log-test/{$property->code}")->assertNoContent();

    $event = MailEvent::query()->sole();

    expect($event->actor_type)->toBe($type)
        ->and($event->actor_user_id)->toBe($user->id)
        ->and($event->property_id)->toBe($property->id);
})->with([
    'staff' => [fn (Property $property) => User::factory()->for($property)->create(), MailActorType::Property],
    'partner' => [fn () => User::factory()->partner()->create(), MailActorType::Partner],
    'admin' => [fn () => User::factory()->admin()->create(), MailActorType::Admin],
    'company' => [fn () => User::factory()->companyUser()->create(), MailActorType::Company],
]);

it('records the system when nobody is signed in and falls back to the recipient property', function () {
    $property = Property::factory()->create();
    $staff = User::factory()->for($property)->create();

    Mail::raw('Hi', fn ($message) => $message->to($staff->email)->subject('Audit'));

    $event = MailEvent::query()->sole();

    expect($event->actor_type)->toBe(MailActorType::System)
        ->and($event->actor_user_id)->toBeNull()
        ->and($event->property_id)->toBe($property->id);
});

it('records a guest on the booking engine api', function () {
    $property = Property::factory()->create();
    Route::get('/mail-log-test-guest/{code}', function () {
        Mail::raw('Hi', fn ($message) => $message->to('guest@example.test')->subject('Booking'));

        return response()->noContent();
    })->name('api.booking.v1.mail-log-test');

    $this->get("/mail-log-test-guest/{$property->code}")->assertNoContent();

    $event = MailEvent::query()->sole();

    expect($event->actor_type)->toBe(MailActorType::Guest)
        ->and($event->property_id)->toBe($property->id);
});

it('keeps the actor for a mail sent later from the queue', function () {
    $property = Property::factory()->create();
    $staff = User::factory()->for($property)->create();
    $this->actingAs($staff);

    $payload = Context::dehydrate();

    Auth::logout();
    Context::flush();
    Context::hydrate($payload);

    Mail::raw('Hi', fn ($message) => $message->to('someone@example.test')->subject('Queued'));

    $event = MailEvent::query()->sole();

    expect($event->actor_type)->toBe(MailActorType::Property)
        ->and($event->actor_user_id)->toBe($staff->id);
});

it('shows who triggered each mail and filters by property', function () {
    $admin = User::factory()->admin()->create(['name' => 'Daniel']);
    $seaview = Property::factory()->create(['name' => 'Seaview Hotel']);
    $staff = User::factory()->for($seaview)->create(['name' => 'Aisyah']);

    MailEvent::factory()->create(['subject' => 'Staff', 'property_id' => $seaview->id, 'actor_type' => MailActorType::Property, 'actor_user_id' => $staff->id]);
    MailEvent::factory()->create(['subject' => 'Admin', 'actor_type' => MailActorType::Admin, 'actor_user_id' => $admin->id, 'sent_at' => now()->subMinute()]);
    MailEvent::factory()->create(['subject' => 'System', 'actor_type' => MailActorType::System, 'sent_at' => now()->subMinutes(2)]);
    MailEvent::factory()->create(['subject' => 'Old', 'actor_type' => null, 'sent_at' => now()->subMinutes(3)]);
    MailEvent::factory()->create(['subject' => 'Admin at property', 'property_id' => $seaview->id, 'actor_type' => MailActorType::Admin, 'actor_user_id' => $admin->id, 'sent_at' => now()->subMinutes(4)]);
    MailEvent::factory()->create(['subject' => 'Old with property', 'property_id' => $seaview->id, 'actor_type' => null, 'sent_at' => now()->subMinutes(5)]);

    $rows = fn (array $query = []) => collect($this->actingAs($admin)->get(route('admin.mail-events.index', $query))
        ->assertOk()
        ->viewData('page')['props']['events']['data'])->keyBy('subject');

    $all = $rows();

    expect($all['Staff']['triggeredByTitle'])->toBe('Seaview Hotel')
        ->and($all['Staff']['triggeredByDetail'])->toBe('Property · Aisyah')
        ->and($all['Admin']['triggeredByTitle'])->toBe('Admin')
        ->and($all['Admin']['triggeredByDetail'])->toBe('Daniel')
        ->and($all['Admin at property']['triggeredByTitle'])->toBe('Seaview Hotel')
        ->and($all['Admin at property']['triggeredByDetail'])->toBe('Admin · Daniel')
        ->and($all['Old with property']['triggeredByTitle'])->toBe('Seaview Hotel')
        ->and($all['Old with property']['triggeredByDetail'])->toBeNull()
        ->and($all['System']['triggeredByTitle'])->toBe('System')
        ->and($all['System']['triggeredByDetail'])->toBeNull()
        ->and($all['Old']['triggeredByTitle'])->toBeNull()
        ->and($rows(['filter' => ['property' => (string) $seaview->id]])->keys()->all())->toBe(['Staff', 'Admin at property', 'Old with property']);
});

it('records the property of a mail to someone who is not staff, also from the queue', function () {
    $property = Property::factory()->create();

    $payload = null;
    Context::scope(function () use (&$payload): void {
        $payload = Context::dehydrate();
    }, hidden: [MailActorResolver::PROPERTY_CONTEXT_KEY => $property->id]);

    expect(Context::hasHidden(MailActorResolver::PROPERTY_CONTEXT_KEY))->toBeFalse();

    Context::hydrate($payload);
    Mail::raw('Hi', fn ($message) => $message->to('guest@example.test')->subject('Booking'));

    expect(MailEvent::query()->sole()->property_id)->toBe($property->id);
});

it('backfills the property of old guest mail from a unique property name in the subject', function () {
    $sandbay = Property::factory()->create(['name' => 'Sandbay Resort']);
    Property::factory()->count(2)->create(['name' => 'Twin Hotel']);

    $old = fn (string $type, string $subject) => MailEvent::factory()->create(['notification_type' => $type, 'subject' => $subject]);
    $confirmed = $old('OnlineBookingConfirmed', 'Your booking at Sandbay Resort is confirmed');
    $reminder = $old('AgentPaymentReminder', 'Payment reminder · Sandbay Resort');
    $ambiguous = $old('OnlineBookingConfirmed', 'Your booking at Twin Hotel is confirmed');
    $otherType = $old('GoLiveRequested', 'Your booking at Sandbay Resort is confirmed');

    (require database_path('migrations/2026_11_09_120200_backfill_guest_mail_event_properties.php'))->up();

    expect($confirmed->refresh()->property_id)->toBe($sandbay->id)
        ->and($reminder->refresh()->property_id)->toBe($sandbay->id)
        ->and($ambiguous->refresh()->property_id)->toBeNull()
        ->and($otherType->refresh()->property_id)->toBeNull();
});

it('backfills only the old rows whose actor is known', function () {
    $staffRun = Property::factory()->create();
    $staff = User::factory()->for($staffRun)->create();
    $scheduled = Property::factory()->create();

    NightAudit::factory()->completed()->create(['business_date_id' => BusinessDate::factory()->for($staffRun)->auditing()->create()->id, 'run_by' => $staff->id]);
    NightAudit::factory()->completed()->create(['business_date_id' => BusinessDate::factory()->for($scheduled)->auditing()->create()->id, 'trigger' => AuditTrigger::Scheduled]);

    $old = fn (?string $type, ?Property $property = null, array $extra = []) => MailEvent::factory()->create(['notification_type' => $type, 'property_id' => $property?->id, ...$extra]);
    $manualAudit = $old('NightAuditCompleted', $staffRun);
    $scheduledAudit = $old('NightAuditExceptions', $scheduled);
    $noAudit = $old('NightAuditCompleted', Property::factory()->create());
    $booking = $old('OnlineBookingReceived', $staffRun);
    $goLive = $old('GoLiveApproved', $staffRun);
    $alreadySet = $old('OnlineBookingConfirmed', null, ['actor_type' => MailActorType::Guest]);

    (require database_path('migrations/2026_11_09_120100_backfill_mail_event_actors.php'))->up();

    expect($manualAudit->refresh()->actor_type)->toBe(MailActorType::Property)
        ->and($manualAudit->actor_user_id)->toBe($staff->id)
        ->and($scheduledAudit->refresh()->actor_type)->toBe(MailActorType::System)
        ->and($scheduledAudit->actor_user_id)->toBeNull()
        ->and($noAudit->refresh()->actor_type)->toBeNull()
        ->and($booking->refresh()->actor_type)->toBe(MailActorType::System)
        ->and($goLive->refresh()->actor_type)->toBeNull()
        ->and($alreadySet->refresh()->actor_type)->toBe(MailActorType::Guest);
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
