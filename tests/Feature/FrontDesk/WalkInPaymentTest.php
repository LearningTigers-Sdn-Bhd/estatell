<?php

require_once __DIR__.'/../Support/BookingSetup.php';
require_once __DIR__.'/../Support/ChannelStructure.php';
require_once __DIR__.'/../Support/RecordingQueue.php';

use App\Enums\SystemTransactionCode;
use App\Jobs\FlushChannelAriJob;
use App\Models\ChannelAriChange;
use App\Models\Company;
use App\Models\DocumentSequence;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Folio\ScheduledFolioRows;
use Brick\Math\BigDecimal;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 20)->setTime(12, 0));
});

/**
 * The booking setup with an 8% tax on Room revenue flagged Collect at check-in, a 100.00 security deposit and a booker
 * of the property's country. Two nights at 100.00 give 216.00 for each unit, 16.00 of it tax.
 *
 * @return array<string, mixed>
 */
function walkInPaymentSetup(array $settings = [], bool $foreignOnly = false): array
{
    $setup = bookingSetup();
    $setup['property']->settings->update(['security_deposit_amount' => '100.00', ...$settings]);
    $setup['guest']->update(['nationality_code' => 'MY']);
    $room = TransactionCode::factory()->for($setup['property'])->system(SystemTransactionCode::RoomRevenue)->create();
    $setup['tax'] = Tax::factory()->for($setup['property'])->create(['name' => 'Tourism tax', 'rate' => '8.00', 'is_inclusive' => false, 'collect_at_check_in' => true, 'foreign_guests_only' => $foreignOnly]);
    $setup['tax']->applicableTransactionCodes()->attach($room);

    return $setup;
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function walkInPaymentBody(array $setup, array $overrides = []): array
{
    return [
        'booking_source_id' => $setup['phone']->id,
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-22',
        'guests' => [['guest_id' => $setup['guest']->id]],
        'billing_mode' => 'each_pays',
        'lines' => [
            ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 2, 'child_ages' => []],
            ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 2, 'child_ages' => []],
        ],
        ...$overrides,
    ];
}

function walkInPaymentUrl(array $setup): string
{
    return route('reservations.walk-in-payment', ['property' => $setup['property']->code]);
}

test('the preview answers the full bill, the taxes and the deposit of a booking and equals the same booking made for real', function () {
    $setup = walkInPaymentSetup();
    $body = walkInPaymentBody($setup);

    $this->actingAs($setup['user'])->postJson(walkInPaymentUrl($setup), $body)
        ->assertOk()
        ->assertExactJson([
            'roomCharges' => '432.00',
            'roomChargesHidden' => false,
            'taxes' => [['name' => 'Tourism tax', 'amount' => '32.00', 'exempt' => false]],
            'taxesTotal' => '32.00',
            'deposit' => ['amount' => '100.00', 'units' => 2, 'total' => '200.00'],
            'totalWithRoomCharges' => '632.00',
            'totalWithoutRoomCharges' => '232.00',
            'errors' => [],
        ]);

    $this->post(route('reservations.store', ['property' => $setup['property']->code]), $body)->assertSessionHasNoErrors();

    $reservation = Reservation::query()->with('booker')->sole();
    $scheduled = collect(app(ScheduledFolioRows::class)->forReservation($setup['property']->load('settings'), $reservation, []))
        ->flatMap(fn (array $block): array => $block['rows'])
        ->reduce(fn (BigDecimal $sum, $row): BigDecimal => $sum->plus($row->amount), BigDecimal::zero());

    expect((string) $scheduled->toScale(2))->toBe('432.00');
});

test('a preview keeps nothing: no reservation, guest, log, reference number or channel line', function () {
    $queued = recordQueuedJobs();
    $setup = walkInPaymentSetup();
    $channelProperty = channelSyncedProperty($setup['property']);
    $channelProperty->roomTypeMappings()->create(['room_type_id' => $setup['roomType']->id, 'external_room_type_id' => 'room-1']);
    $body = walkInPaymentBody($setup);

    $this->actingAs($setup['user'])->post(route('reservations.store', ['property' => $setup['property']->code]), $body)->assertSessionHasNoErrors();

    $counts = fn (): array => [
        Reservation::query()->count(),
        Guest::query()->count(),
        Folio::query()->count(),
        UnitNight::query()->count(),
        Activity::query()->count(),
        ChannelAriChange::query()->count(),
        DocumentSequence::query()->sum('last_number'),
        count($queued),
    ];
    $before = $counts();

    expect($before[0])->toBe(1)->and($before[5])->toBe(1)->and($before[6])->toBeGreaterThan(0);

    $newGuest = ['full_name' => 'Nur Aina', 'nationality_code' => 'MY'];
    $this->postJson(walkInPaymentUrl($setup), [...$body, 'arrival_date' => '2026-10-23', 'departure_date' => '2026-10-25', 'guests' => [$newGuest]])->assertOk()->assertJsonPath('errors', []);

    expect($counts())->toBe($before)
        ->and($queued->getArrayCopy())->toBe([FlushChannelAriJob::class]);
});

test('a booking that would be refused answers 200 with the errors of a save and keeps nothing', function () {
    $setup = walkInPaymentSetup();
    $line = ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 2, 'child_ages' => []];

    $this->actingAs($setup['user'])
        ->postJson(walkInPaymentUrl($setup), walkInPaymentBody($setup, ['lines' => [$line, $line, $line]]))
        ->assertOk()
        ->assertJsonPath('errors', ['lines.2.room_type_id' => 'No unit is available on 20 Oct 2026.'])
        ->assertJsonPath('roomCharges', '0.00')
        ->assertJsonPath('deposit', null);

    expect(Reservation::query()->count())->toBe(0)->and(Activity::query()->count())->toBe(0);
});

test('a foreign-only tax is exempt for a booker of the property country and charged for a foreign booker', function () {
    $setup = walkInPaymentSetup(foreignOnly: true);
    $body = walkInPaymentBody($setup, ['lines' => [walkInPaymentBody($setup)['lines'][0]]]);

    $this->actingAs($setup['user'])->postJson(walkInPaymentUrl($setup), $body)
        ->assertOk()
        ->assertJsonPath('taxes', [['name' => 'Tourism tax', 'amount' => '0.00', 'exempt' => true]])
        ->assertJsonPath('taxesTotal', '0.00')
        ->assertJsonPath('roomCharges', '200.00')
        ->assertJsonPath('totalWithoutRoomCharges', '100.00');

    $setup['guest']->update(['nationality_code' => 'SG']);

    $this->postJson(walkInPaymentUrl($setup), $body)
        ->assertOk()
        ->assertJsonPath('taxes', [['name' => 'Tourism tax', 'amount' => '16.00', 'exempt' => false]])
        ->assertJsonPath('roomCharges', '216.00')
        ->assertJsonPath('totalWithRoomCharges', '316.00')
        ->assertJsonPath('totalWithoutRoomCharges', '116.00');
});

test('a company on Master folio pays hides the room charges and still shows the deposit', function () {
    $setup = walkInPaymentSetup();
    $company = Company::factory()->for($setup['property'])->create();

    $this->actingAs($setup['user'])->postJson(walkInPaymentUrl($setup), walkInPaymentBody($setup, ['company_id' => $company->id, 'billing_mode' => 'master_pays']))
        ->assertOk()
        ->assertJsonPath('roomChargesHidden', true)
        ->assertJsonPath('taxes', [])
        ->assertJsonPath('deposit.total', '200.00')
        ->assertJsonPath('totalWithRoomCharges', '200.00')
        ->assertJsonPath('totalWithoutRoomCharges', '200.00');

    $this->postJson(walkInPaymentUrl($setup), walkInPaymentBody($setup, ['company_id' => $company->id, 'billing_mode' => 'each_pays']))
        ->assertOk()
        ->assertJsonPath('roomChargesHidden', false)
        ->assertJsonPath('totalWithRoomCharges', '632.00');
});

test('a group on Master folio pays without a company pays its room charges but has no tax rows to collect', function () {
    $setup = walkInPaymentSetup();

    $this->actingAs($setup['user'])->postJson(walkInPaymentUrl($setup), walkInPaymentBody($setup, ['billing_mode' => 'master_pays']))
        ->assertOk()
        ->assertJsonPath('roomChargesHidden', false)
        ->assertJsonPath('roomCharges', '432.00')
        ->assertJsonPath('taxes', [])
        ->assertJsonPath('totalWithRoomCharges', '632.00')
        ->assertJsonPath('totalWithoutRoomCharges', '200.00');
});

test('the deposit is null when the property sets no amount', function () {
    $setup = walkInPaymentSetup(['security_deposit_amount' => '0.00']);

    $this->actingAs($setup['user'])->postJson(walkInPaymentUrl($setup), walkInPaymentBody($setup))
        ->assertOk()
        ->assertJsonPath('deposit', null)
        ->assertJsonPath('totalWithRoomCharges', '432.00')
        ->assertJsonPath('totalWithoutRoomCharges', '32.00');
});

test('the preview needs reservations.manage and payment.record and stays in its property', function () {
    $setup = walkInPaymentSetup();
    $user = fn (array $permissions): User => User::factory()->for($setup['property'])->withPermissions($permissions)->create();

    $this->actingAs($user(['reservations.manage']))->postJson(walkInPaymentUrl($setup), walkInPaymentBody($setup))->assertForbidden();
    $this->actingAs($user(['payment.record']))->postJson(walkInPaymentUrl($setup), walkInPaymentBody($setup))->assertForbidden();
    $this->actingAs($user(['reservations.manage', 'payment.record']))->postJson(walkInPaymentUrl($setup), walkInPaymentBody($setup))->assertOk();

    $other = walkInPaymentSetup();
    $this->actingAs($setup['user'])->postJson(walkInPaymentUrl($other), walkInPaymentBody($other))->assertNotFound();

    expect(Reservation::query()->count())->toBe(0);
});

test('the walk-in page tells the form whether it may take a payment and offers the methods on request', function () {
    $setup = walkInPaymentSetup();

    $this->actingAs($setup['user'])->get(route('reservations.walk-in', ['property' => $setup['property']->code]))
        ->assertInertia(fn ($page) => $page->where('canRecordPayment', true)->missing('paymentOptions'));

    $this->get(route('reservations.walk-in', ['property' => $setup['property']->code]), ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'reservations/create', 'X-Inertia-Partial-Data' => 'paymentOptions', 'X-Inertia-Version' => inertia()->getVersion()])
        ->assertJsonStructure(['props' => ['paymentOptions' => ['methods']]]);

    $this->actingAs(User::factory()->for($setup['property'])->withPermissions(['reservations.manage'])->create())
        ->get(route('reservations.walk-in', ['property' => $setup['property']->code]))
        ->assertInertia(fn ($page) => $page->where('canRecordPayment', false));
});
