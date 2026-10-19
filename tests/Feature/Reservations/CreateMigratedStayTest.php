<?php

require_once __DIR__.'/../Support/MigratedStaySetup.php';

use App\Actions\Folio\PostingResult;
use App\Actions\Reservations\BookingResult;
use App\Enums\OldFolioMode;
use App\Enums\UnitStatus;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\Folio\FolioBalance;
use App\Services\Reservations\BookingSummary;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('a migrated stay is checked in on its arrival, charges only from go-live and posts the opening balance on the first folio', function () {
    $setup = migratedStaySetup();

    $result = createMigratedStay($setup);

    $unit = $result->reservation->units()->sole();
    $folio = Folio::query()->where('reservation_unit_id', $unit->id)->sole();
    $opening = FolioLine::query()->where('idempotency_key', 'opening:'.$folio->id.':1')->sole();

    expect($result->refusal)->toBeNull()
        ->and($unit->status)->toBe(UnitStatus::CheckedIn)
        ->and($unit->checked_in_at->format('Y-m-d H:i'))->toBe('2026-10-08 15:00')
        ->and($unit->nights()->count())->toBe(8)
        ->and(nightServiceDates())->toBe(['2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13'])
        ->and($unit->nights()->whereDate('date', '<', '2026-10-10')->pluck('amount')->all())->each->toBe('100.00')
        ->and($folio->legacy_number)->toBe('F1203')
        ->and($opening->description)->toBe('Opening balance · old folio F1203')
        ->and((string) $opening->amount)->toBe('1080.00')
        ->and($opening->posting_date->toDateString())->toBe('2026-10-14')
        ->and($opening->service_date->toDateString())->toBe('2026-10-10')
        ->and($opening->parent_line_id)->toBeNull()
        ->and(FolioLine::query()->where('parent_line_id', $opening->id)->exists())->toBeFalse()
        ->and(app(FolioBalance::class)->forFolio($folio))->toBe('1480.00');

    $log = Activity::query()->where('event', 'reservation.migrated_stay_created')->sole();
    expect($log->properties['reason'])->toBe('Migrated from old PMS')
        ->and($log->properties['units'])->toBe([$unit->id]);
});

test('a credit is a negative opening balance, zero posts nothing and Copy line by line saves no opening line', function (OldFolioMode $mode, ?string $amount, ?string $expected) {
    $setup = migratedStaySetup();

    $result = createMigratedStay($setup, mode: $mode, amount: $amount);

    $lines = FolioLine::query()->where('idempotency_key', 'like', 'opening:%')->get();
    expect($result->refusal)->toBeNull()
        ->and($lines->map(fn ($line): string => (string) $line->amount)->all())->toBe($expected === null ? [] : [$expected])
        ->and(Folio::query()->whereNotNull('legacy_number')->count())->toBe($expected === null ? 0 : 1);
})->with([
    'credit' => [OldFolioMode::OneAmount, '-50.00', '-50.00'],
    'zero' => [OldFolioMode::OneAmount, '0.00', null],
    'empty' => [OldFolioMode::OneAmount, '', null],
    'copy line by line' => [OldFolioMode::CopyLineByLine, '999.00', null],
]);

test('a migrated stay is refused for a bad date, reason, amount or a closed window and nothing is saved', function (array $line, string $reason, ?string $amount, int $window, string $refusal) {
    $setup = migratedStaySetup($window);

    $result = createMigratedStay($setup, $line, $reason, amount: $amount);

    expect($result->refusal)->toBe($refusal)
        ->and(Reservation::query()->count())->toBe(0)
        ->and(FolioLine::query()->count())->toBe(0);
})->with([
    'began after go-live' => [['arrivalDate' => '2026-10-10'], 'Migrated', '10.00', 60, BookingResult::REFUSAL_ARRIVAL_NOT_BEFORE_GO_LIVE],
    'already left' => [['departureDate' => '2026-10-14'], 'Migrated', '10.00', 60, BookingResult::REFUSAL_DEPARTURE_NOT_AFTER_TODAY],
    'no reason' => [[], '  ', '10.00', 60, BookingResult::REFUSAL_REASON_REQUIRED],
    'not an amount' => [[], 'Migrated', 'abc', 60, PostingResult::REFUSAL_OPENING_AMOUNT_INVALID],
    'window closed' => [[], 'Migrated', '10.00', 3, BookingResult::REFUSAL_MIGRATION_WINDOW_CLOSED],
    'no window' => [[], 'Migrated', '10.00', 0, BookingResult::REFUSAL_MIGRATION_WINDOW_CLOSED],
]);

test('availability is checked for the nights before go-live', function () {
    $setup = migratedStaySetup();
    pastStayUnit($setup, '2026-10-08', '2026-10-09', ['status' => UnitStatus::CheckedOut, 'checked_in_at' => '2026-10-08 15:00:00', 'checked_out_at' => '2026-10-09 11:00:00']);

    $taken = createMigratedStay($setup);
    $other = createMigratedStay([...$setup, 'rooms' => [$setup['rooms'][1]]]);

    expect($taken->isRefused())->toBeTrue()
        ->and($taken->refusal)->toBe('room_not_free')
        ->and($other->isRefused())->toBeFalse()
        ->and(ReservationUnit::query()->count())->toBe(2);
});

test('the Migrated stay page opens for folio.manual inside the window and sends everyone else away', function () {
    $setup = migratedStaySetup();
    $url = route('reservations.migrated', ['property' => $setup['property']->code]);

    $this->actingAs($setup['user'])->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reservations/create')
            ->where('isMigratedStay', true)
            ->where('isPastStay', true)
            ->where('canEnterMigratedStay', true)
            ->where('goLiveDate', '2026-10-10')
            ->where('oldFolioModes.0.value', 'one_amount'));

    $this->get(route('reservations.new', ['property' => $setup['property']->code]))
        ->assertInertia(fn (Assert $page) => $page->where('canEnterMigratedStay', true)->where('isMigratedStay', false));

    actionsRoleWith($setup['user'], ['reservations.manage']);
    $this->get($url)->assertForbidden();
    $this->get(route('reservations.new', ['property' => $setup['property']->code]))
        ->assertInertia(fn (Assert $page) => $page->where('canEnterMigratedStay', false));
});

test('after the window the mode is hidden and its page goes back to Past stay with an error', function () {
    $setup = migratedStaySetup(3);

    $this->actingAs($setup['user'])->get(route('reservations.migrated', ['property' => $setup['property']->code]))
        ->assertRedirect(route('reservations.past', ['property' => $setup['property']->code]));
    $this->get(route('reservations.past', ['property' => $setup['property']->code]))
        ->assertInertia(fn (Assert $page) => $page->where('canEnterMigratedStay', false));
});

test('saving a migrated stay posts to the past route with a mode and opens the folio tab', function () {
    $setup = migratedStaySetup();

    $response = $this->actingAs($setup['user'])->post(route('reservations.store-past', ['property' => $setup['property']->code]), migratedStayBody($setup));

    $reservation = Reservation::query()->sole();
    $response->assertRedirect(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $reservation]));
    expect(FolioLine::query()->where('idempotency_key', 'like', 'opening:%')->sole()->amount)->toBe('1080.00')
        ->and(nightServiceDates())->toBe(['2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13']);
});

test('Copy line by line saves no opening line and opens the old folio page', function () {
    $setup = migratedStaySetup();

    $response = $this->actingAs($setup['user'])->post(route('reservations.store-past', ['property' => $setup['property']->code]), migratedStayBody($setup, ['old_folio' => 'copy_line_by_line', 'opening_amount' => '']));

    $response->assertRedirect(route('reservations.old-folio', ['property' => $setup['property']->code, 'reservation' => Reservation::query()->sole()]));
    expect(FolioLine::query()->where('idempotency_key', 'like', 'opening:%')->count())->toBe(0);
});

test('a refused migrated stay goes back to the form with the error and saves nothing', function () {
    $setup = migratedStaySetup();
    $url = route('reservations.store-past', ['property' => $setup['property']->code]);

    $this->actingAs($setup['user'])->post($url, migratedStayBody($setup, ['arrival_date' => '2026-10-10']))->assertSessionHasErrors('arrival_date');
    $this->post($url, migratedStayBody($setup, ['opening_amount' => 'abc']))->assertSessionHasErrors('opening_amount');
    $this->post($url, migratedStayBody($setup, ['old_folio' => null]))->assertSessionHasErrors('old_folio');
    expect(Reservation::query()->count())->toBe(0);
});

test('Scheduled rows and the booking summary leave the nights before go-live out', function () {
    $setup = migratedStaySetup();
    $result = createMigratedStay($setup, mode: OldFolioMode::CopyLineByLine);
    $reservation = $result->reservation;

    $summary = app(BookingSummary::class)->for($setup['property'], $reservation);
    $scheduled = [];
    $this->actingAs($setup['user'])->get(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $reservation]))
        ->assertInertia(function (Assert $page) use (&$scheduled) {
            $scheduled = collect($page->toArray()['props']['folio']['rows'])->where('state', 'scheduled')->pluck('date')->unique()->values()->all();
        });

    expect($scheduled)->toBe(['2026-10-14', '2026-10-15'])
        ->and($summary->total())->toBe('600.00');
});

test('the Opening balance code is not a charge item and cannot be used by one', function () {
    $setup = migratedStaySetup();

    expect(ChargeItem::query()->where('property_id', $setup['property']->id)->count())->toBe(0);

    $this->actingAs($setup['user'])->post(route('settings.charge-items.store', $setup['property']->code), [
        'name' => 'Old balance', 'code' => 'OPENING_BAL', 'category' => 'fee', 'charging_unit' => 'per_item', 'allow_override' => true, 'active' => true,
    ])->assertSessionHasErrors('code');
    $this->post(route('settings.charge-items.store', $setup['property']->code), [
        'name' => 'Old balance', 'code' => 'OLD_BAL', 'category' => 'opening_balance', 'charging_unit' => 'per_item', 'allow_override' => true, 'active' => true,
    ])->assertSessionHasErrors('category');
});

test('a migrated stay needs a reason and a room', function () {
    $setup = migratedStaySetup();
    $url = route('reservations.store-past', ['property' => $setup['property']->code]);
    $noRoom = migratedStayBody($setup);
    unset($noRoom['lines'][0]['room_id']);

    $this->actingAs($setup['user'])->post($url, migratedStayBody($setup, ['reason' => '']))->assertSessionHasErrors('reason');
    $this->post($url, $noRoom)->assertSessionHasErrors('lines.0.room_id');
    expect(Reservation::query()->count())->toBe(0);
});
