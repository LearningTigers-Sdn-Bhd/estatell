<?php

use App\Actions\Events\PostEventCharge;
use App\Actions\Folio\CloseFolio;
use App\Actions\Payments\RecordPayment;
use App\Data\Folio\NewChargeData;
use App\Data\Payments\NewPaymentData;
use App\Enums\EventStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PermissionEnum;
use App\Features\Facilities;
use App\Models\Company;
use App\Models\Event;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/EventSetup.php';

test('the event pages need the Facilities feature and events.view', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    [$event] = makeEvent($property, $owner);
    $clerk = User::factory()->for($property)->withPermissions([PermissionEnum::ReservationsView])->create();

    $this->actingAs($clerk)->get(route('events.index', $property->code))->assertForbidden();
    $this->actingAs($clerk)->get(route('events.show', [$property->code, $event]))->assertForbidden();

    Feature::for($property)->deactivate(Facilities::class);

    $this->actingAs($owner)->get(route('events.index', $property->code))->assertNotFound();
});

test('an event of another property and a folio of another event are not found', function () {
    ['property' => $property, 'owner' => $owner, 'hall' => $hall] = eventSetup();
    [$event] = makeEvent($property, $owner);
    [, $otherFolio] = makeEvent($property, $owner);
    ['property' => $other, 'owner' => $otherOwner] = eventSetup();
    [$foreign] = makeEvent($other, $otherOwner);

    $this->actingAs($owner)->get(route('events.show', [$property->code, $foreign]))->assertNotFound();
    $this->actingAs($owner)->post(route('events.folios.charges.store', [$property->code, $event, $otherFolio]), [
        'folio_id' => $otherFolio->id,
        'charge_item_id' => $hall->id,
        'quantity' => 1,
        'unit_amount' => '1000.00',
        'idempotency_key' => 'manual:'.fake()->uuid(),
    ])->assertNotFound();

    expect(FolioLine::query()->count())->toBe(0);
});

test('the Events list shows the events of this month with each folio balance', function () {
    ['property' => $property, 'owner' => $owner, 'hall' => $hall] = eventSetup();
    [$wedding, $folio] = makeEvent($property, $owner);
    makeEvent($property, $owner, ['name' => 'Year-end dinner', 'starts_on' => '2026-12-12', 'ends_on' => '2026-12-12']);
    app(PostEventCharge::class)($owner, $folio, new NewChargeData($hall->id, null, null, 1, '1000.00', 'manual:'.fake()->uuid()));

    $this->actingAs($owner)->get(route('events.index', $property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('events/index')
            ->where('eventDefaultDate', '2026-10-14')
            ->has('businessDate.date')
            ->where('filters.period', 'this_month')
            ->has('events.data', 1)
            ->where('events.data.0.id', $wedding->id)
            ->where('events.data.0.balance', '1080.00')
            ->where('events.data.0.status.value', 'confirmed'));

    $this->actingAs($owner)->get(route('events.index', [$property->code, 'filter' => ['period' => 'all', 'search' => 'year-end']]))
        ->assertInertia(fn (Assert $page) => $page->has('events.data', 1)->where('events.data.0.name', 'Year-end dinner'));
});

test('staff create an event for a guest and land on its folio', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $guest = Guest::factory()->create(['property_id' => $property->id]);

    $this->actingAs($owner)->post(route('events.store', $property->code), [
        'name' => 'WWF field team lunch',
        'payer_type' => 'guest',
        'payer_guest_id' => $guest->id,
        'starts_on' => '2026-10-14',
        'ends_on' => '2026-10-14',
        'status' => 'confirmed',
    ])->assertSessionHasNoErrors();

    $event = Event::query()->sole();
    expect($event->payer_guest_id)->toBe($guest->id)
        ->and(Folio::query()->where('event_id', $event->id)->count())->toBe(1);
});

test('the New event form checks the payer and the dates', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $otherGuest = Guest::factory()->create(['property_id' => Property::factory()->create()->id]);
    $clerk = User::factory()->for($property)->withPermissions([PermissionEnum::EventsView, PermissionEnum::EventsManage])->create();

    $this->actingAs($owner)->post(route('events.store', $property->code), [
        'name' => 'Lunch',
        'payer_type' => 'guest',
        'payer_guest_id' => $otherGuest->id,
        'starts_on' => '2026-10-15',
        'ends_on' => '2026-10-14',
        'status' => 'completed',
    ])->assertSessionHasErrors(['payer_guest_id', 'ends_on', 'status']);

    $this->actingAs($clerk)->post(route('events.store', $property->code), [
        'name' => 'Lunch',
        'payer_type' => 'company',
        'payer_company_id' => Company::factory()->create(['property_id' => $property->id])->id,
        'starts_on' => '2026-10-14',
        'ends_on' => '2026-10-14',
        'status' => 'confirmed',
    ])->assertSessionHasErrors('payer_type');

    expect(Event::query()->count())->toBe(0);
});

test('the event page shows its folio with Post charge and Record payment, and no routing', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    [$event, $folio] = makeEvent($property, $owner);

    $this->actingAs($owner)->get(route('events.show', [$property->code, $event]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('events/show')
            ->where('event.number', $event->number)
            ->where('event.canConfirm', false)
            ->where('event.canCancel', true)
            ->where('folio.key', (string) $folio->id)
            ->where('folio.kind', 'event')
            ->where('folio.canPostCharge', true)
            ->where('folio.canRecordPayment', true)
            ->where('folio.canMoveCharges', false)
            ->where('folio.canManageManually', false)
            ->where('folio.postFolios', [])
            ->where('folio.paymentPurposes.0.value', 'advance'));

    $this->actingAs($owner)->get(route('events.bookings', [$property->code, $event]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('events/bookings')->where('tab', 'bookings'));
});

test('staff post a charge and take a deposit on the event folio', function () {
    ['property' => $property, 'owner' => $owner, 'hall' => $hall, 'cash' => $cash] = eventSetup();
    [$event, $folio] = makeEvent($property, $owner);

    $this->actingAs($owner)->post(route('events.folios.charges.store', [$property->code, $event, $folio]), [
        'folio_id' => $folio->id,
        'charge_item_id' => $hall->id,
        'quantity' => 1,
        'unit_amount' => '1000.00',
        'idempotency_key' => 'manual:'.fake()->uuid(),
    ])->assertSessionHasNoErrors();

    $this->actingAs($owner)->post(route('events.folios.payments.store', [$property->code, $event, $folio]), [
        'payment_method_id' => $cash->id,
        'purpose' => 'advance',
        'amount' => '500.00',
        'idempotency_key' => fake()->uuid(),
    ])->assertSessionHasNoErrors()->assertRedirect(route('events.show', [$property->code, $event]));

    expect(FolioLine::query()->where('folio_id', $folio->id)->sum('amount'))->toEqual('580.00')
        ->and(Payment::query()->sole()->purpose)->toBe(PaymentPurpose::Advance);
});

test('confirm, cancel and edit need events.manage', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    [$event] = makeEvent($property, $owner, ['status' => EventStatus::Tentative]);
    $viewer = User::factory()->for($property)->withPermissions([PermissionEnum::EventsView])->create();

    $this->actingAs($viewer)->post(route('events.confirm', [$property->code, $event]))->assertForbidden();
    $this->actingAs($viewer)->post(route('events.cancel', [$property->code, $event]))->assertForbidden();

    $this->actingAs($owner)->post(route('events.confirm', [$property->code, $event]))->assertRedirect();

    expect($event->refresh()->status)->toBe(EventStatus::Confirmed);
});

test('the invoice of a closed event folio and the receipt of its payment print', function () {
    ['property' => $property, 'owner' => $owner, 'hall' => $hall, 'cash' => $cash] = eventSetup();
    [$event, $folio] = makeEvent($property, $owner);
    app(PostEventCharge::class)($owner, $folio, new NewChargeData($hall->id, null, null, 1, '1000.00', 'manual:'.fake()->uuid()));
    $payment = app(RecordPayment::class)->forEvent($owner, $folio, NewPaymentData::from([
        'paymentMethodId' => $cash->id,
        'purpose' => PaymentPurpose::Advance,
        'amount' => '1080.00',
        'reference' => null,
        'idempotencyKey' => fake()->uuid(),
    ]))->payment;
    app(CloseFolio::class)($owner, $folio);

    $this->actingAs($owner)->get(route('events.folios.invoice', [$property->code, $event, $folio]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->actingAs($owner)->get(route('events.folios.payments.receipt', [$property->code, $event, $folio, $payment]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('the front desk sidebar shows Events only with the Facilities feature', function () {
    ['property' => $property, 'owner' => $owner] = eventSetup();
    $titles = fn ($response) => collect(data_get($response->viewData('page'), 'props.navigation.contexts'))
        ->flatMap(fn ($context) => collect($context['groups'])->flatMap(fn ($group) => $group['items']))
        ->pluck('title');

    expect($titles($this->actingAs($owner)->get(route('dashboard', $property->code))))->toContain('Events');

    Feature::for($property)->deactivate(Facilities::class);

    expect($titles($this->actingAs($owner)->get(route('dashboard', $property->code))))->not->toContain('Events');
});
