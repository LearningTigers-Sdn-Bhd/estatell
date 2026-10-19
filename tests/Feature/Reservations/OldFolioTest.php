<?php

require_once __DIR__.'/../Support/MigratedStaySetup.php';

use App\Actions\Folio\PrepareOldFolios;
use App\Actions\Folio\SaveOldFolios;
use App\Actions\Folio\SaveOldFoliosResult;
use App\Data\Folio\OldFolioRowData;
use App\Enums\FolioType;
use App\Enums\OldFolioMode;
use App\Enums\OldFolioRowKind;
use App\Enums\PaymentMethodType;
use App\Enums\PermissionEnum;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * A migrated stay in Copy line by line mode (arrival 2026-10-08, go-live 2026-10-10, business date 2026-10-14) with an
 * Old system method, a Minibar charge item and an SST tax. `folio` is the unit's own folio.
 *
 * @return array<string, mixed>
 */
function oldFolioSetup(OldFolioMode $mode = OldFolioMode::CopyLineByLine, int $window = 60): array
{
    $setup = migratedStaySetup($window);
    $property = $setup['property'];
    setPermissionsTeamId($property->id);
    $code = TransactionCode::factory()->system(SystemTransactionCode::OldSystemPayment)->create(['property_id' => $property->id]);
    $setup['old'] = PaymentMethod::factory()->inactive()->create(['property_id' => $property->id, 'transaction_code_id' => $code->id, 'name' => 'Old system', 'type' => PaymentMethodType::OldSystem, 'requires_reference' => true]);
    $setup['minibar'] = ChargeItem::factory()->create(['property_id' => $property->id, 'name' => 'Minibar', 'default_amount' => '3.00', 'allow_override' => true]);
    $setup['sst'] = Tax::factory()->for($property)->create(['name' => 'SST 8%', 'rate' => '8.00', 'rate_type' => TaxRateType::Percent, 'is_inclusive' => false]);
    $reservation = createMigratedStay($setup, mode: $mode, amount: '1080.00')->reservation;
    $setup['reservation'] = $reservation;
    $setup['unit'] = $reservation->units()->sole();
    $setup['folio'] = Folio::query()->where('reservation_unit_id', $setup['unit']->id)->sole();

    return $setup;
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $attributes
 */
function oldRow(array $setup, OldFolioRowKind $kind, array $attributes = []): OldFolioRowData
{
    return new OldFolioRowData(...[
        'key' => (string) Str::uuid(),
        'kind' => $kind,
        'serviceDate' => '2026-10-08',
        'folioId' => $setup['folio']->id,
        'unitId' => $kind === OldFolioRowKind::Room ? $setup['unit']->id : null,
        'chargeItemId' => $kind === OldFolioRowKind::Charge ? $setup['minibar']->id : null,
        'taxId' => $kind === OldFolioRowKind::Tax ? $setup['sst']->id : null,
        'paymentMethodId' => $kind === OldFolioRowKind::Payment ? $setup['old']->id : null,
        'amount' => '100.00',
        ...$attributes,
    ]);
}

/**
 * Two room nights, a Minibar charge, an SST tax row and an Old system payment.
 *
 * @param  array<string, mixed>  $setup
 * @return list<OldFolioRowData>
 */
function oldRows(array $setup): array
{
    return [
        oldRow($setup, OldFolioRowKind::Room),
        oldRow($setup, OldFolioRowKind::Room, ['serviceDate' => '2026-10-09', 'amount' => '120.00']),
        oldRow($setup, OldFolioRowKind::Charge, ['quantity' => 2, 'amount' => '6.00', 'description' => '2 x Coke']),
        oldRow($setup, OldFolioRowKind::Tax, ['amount' => '17.76', 'description' => 'SST 8%', 'serviceDate' => '2026-10-09']),
        oldRow($setup, OldFolioRowKind::Payment, ['amount' => '150.00', 'description' => 'OR-1203', 'serviceDate' => '2026-10-09']),
    ];
}

/**
 * @param  list<OldFolioRowData>  $rows
 * @return array<string, mixed>
 */
function oldBody(array $rows): array
{
    return ['rows' => array_map(fn (OldFolioRowData $row): array => [
        'key' => $row->key,
        'kind' => $row->kind->value,
        'service_date' => $row->serviceDate,
        'folio_id' => $row->folioId,
        'unit_id' => $row->unitId,
        'charge_item_id' => $row->chargeItemId,
        'tax_id' => $row->taxId,
        'payment_method_id' => $row->paymentMethodId,
        'description' => $row->description,
        'quantity' => $row->quantity,
        'amount' => $row->amount,
    ], $rows)];
}

function oldFolioUrl(array $setup): string
{
    return route('reservations.old-folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']]);
}

test('the page opens for folio.manual inside the window and drafts a room night for each night before go-live', function () {
    $setup = oldFolioSetup();

    $this->actingAs($setup['user'])->get(oldFolioUrl($setup))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reservations/old-folio')
        ->where('oldFolio.readOnly', false)
        ->where('oldFolio.goLiveDate', '2026-10-10')
        ->has('oldFolio.tabs', 1)
        ->where('oldFolio.tabs.0.folioId', $setup['folio']->id)
        ->where('oldFolio.tabs.0.legacyNumber', null)
        ->has('oldFolio.tabs.0.rows', 2)
        ->where('oldFolio.tabs.0.rows.0.serviceDate', '2026-10-08')
        ->where('oldFolio.tabs.0.rows.1.serviceDate', '2026-10-09')
        ->where('oldFolio.tabs.0.rows.0.kind', 'room')
        ->where('oldFolio.tabs.0.rows.0.amount', '100.00')
        ->where('oldFolio.tabs.0.rows.0.unitId', $setup['unit']->id)
        ->where('oldFolio.items', fn ($items) => collect($items)->pluck('label')->contains('Old system') && collect($items)->pluck('kind')->unique()->sort()->values()->all() === ['charge', 'payment', 'tax']));
});

test('the page needs folio.manual, an open window and the right property', function () {
    $setup = oldFolioSetup();
    $clerk = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::ReservationsView])->create();

    $this->actingAs($clerk)->get(oldFolioUrl($setup))->assertForbidden();
    $this->actingAs($clerk)->post(oldFolioUrl($setup), oldBody(oldRows($setup)))->assertForbidden();

    $other = oldFolioSetup();
    $this->actingAs($setup['user'])->get(route('reservations.old-folio', ['property' => $setup['property']->code, 'reservation' => $other['reservation']]))->assertNotFound();

    $setup['property']->settings->forceFill(['migration_window_days' => 0])->save();
    $this->actingAs($setup['user'])->get(oldFolioUrl($setup))->assertRedirect(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']]));
    expect(session('inertia.flash_data.toast.type'))->toBe('error');
});

test('a unit without a folio gets its own tab', function () {
    $setup = oldFolioSetup();
    $unit = pastStayUnit($setup, '2026-10-01', '2026-10-03', ['status' => UnitStatus::CheckedIn, 'checked_in_at' => '2026-10-01 15:00:00']);

    expect(app(PrepareOldFolios::class)($unit->reservation))->toBe(1)
        ->and(app(PrepareOldFolios::class)($unit->reservation))->toBe(0)
        ->and(Folio::query()->where('reservation_unit_id', $unit->id)->where('type', FolioType::Unit)->count())->toBe(1);
});

test('saving posts migrated lines with their own dates and keys, a top-level tax line and an Old system payment', function () {
    $setup = oldFolioSetup();
    $rows = oldRows($setup);
    $before = app(FolioBalance::class)->forFolio($setup['folio']);

    $response = $this->actingAs($setup['user'])->post(oldFolioUrl($setup), [...oldBody($rows), 'legacy_numbers' => [$setup['folio']->id => 'F1203']]);

    $response->assertRedirect(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']]));
    $lines = FolioLine::query()->where('migrated', true)->orderBy('id')->get()->keyBy('idempotency_key');
    $key = fn (int $index): string => 'migrated:'.$setup['folio']->id.':'.$rows[$index]->key;

    expect($lines)->toHaveCount(5)
        ->and($lines->every(fn (FolioLine $line): bool => $line->posting_date->toDateString() === '2026-10-14' && $line->parent_line_id === null))->toBeTrue()
        ->and($lines[$key(0)]->service_date->toDateString())->toBe('2026-10-08')
        ->and($lines[$key(0)]->transactionCode->system_key)->toBe(SystemTransactionCode::RoomRevenue->value)
        ->and($lines[$key(0)]->source_type)->toBe($setup['unit']->getMorphClass())
        ->and($lines[$key(0)]->description)->toBe('Room · '.$setup['roomType']->name)
        ->and($lines[$key(1)]->service_date->toDateString())->toBe('2026-10-09')
        ->and((string) $lines[$key(1)]->amount)->toBe('120.00')
        ->and($lines[$key(2)]->quantity)->toBe(2)
        ->and((string) $lines[$key(2)]->unit_amount)->toBe('6.00')
        ->and((string) $lines[$key(2)]->amount)->toBe('12.00')
        ->and($lines[$key(2)]->description)->toBe('2 x Coke')
        ->and(FolioLine::query()->where('parent_line_id', $lines[$key(2)]->id)->exists())->toBeFalse()
        ->and($lines[$key(3)]->kind)->toBe(TransactionKind::Tax)
        ->and($lines[$key(3)]->transaction_code_id)->toBe($setup['sst']->transaction_code_id)
        ->and((string) $lines[$key(3)]->amount)->toBe('17.76')
        ->and($lines[$key(3)]->service_date->toDateString())->toBe('2026-10-09')
        ->and($lines[$key(4)]->kind)->toBe(TransactionKind::Payment)
        ->and((string) $lines[$key(4)]->amount)->toBe('-150.00')
        ->and($lines[$key(4)]->description)->toBe('Paid in old system · OR-1203')
        ->and($setup['folio']->refresh()->legacy_number)->toBe('F1203')
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'night:%')->where('migrated', true)->exists())->toBeFalse();

    $payment = Payment::query()->where('folio_line_id', $lines[$key(4)]->id)->sole();
    expect($payment->paymentMethod->type)->toBe(PaymentMethodType::OldSystem)
        ->and($payment->receipt_number)->toBeNull()
        ->and($payment->reference)->toBe('OR-1203')
        ->and($payment->surcharge_line_id)->toBeNull()
        ->and(app(FolioBalance::class)->forFolio($setup['folio']))->toBe(number_format((float) $before + 100 + 120 + 12 + 17.76 - 150, 2, '.', ''));

    $log = Activity::query()->where('event', 'reservation.old_folios_saved')->sole();
    expect($log->properties['lines'])->toBe(5)
        ->and($log->properties['room_nights'])->toBe(2)
        ->and($log->properties['charges'])->toBe(1)
        ->and($log->properties['taxes'])->toBe(1)
        ->and($log->properties['payments'])->toBe(1);
});

test('a second submit of the same rows writes nothing', function () {
    $setup = oldFolioSetup();
    $body = oldBody(oldRows($setup));

    $this->actingAs($setup['user'])->post(oldFolioUrl($setup), $body)->assertRedirect();
    $this->post(oldFolioUrl($setup), $body)->assertRedirect()->assertSessionHasNoErrors();

    expect(FolioLine::query()->where('migrated', true)->count())->toBe(5)
        ->and(Payment::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'reservation.old_folios_saved')->count())->toBe(1);
});

test('after the first save the page is read only and a new save is refused', function () {
    $setup = oldFolioSetup();
    app(SaveOldFolios::class)($setup['user'], $setup['reservation'], oldRows($setup), [$setup['folio']->id => 'F1203']);

    $this->actingAs($setup['user'])->get(oldFolioUrl($setup))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('oldFolio.readOnly', true)
        ->where('oldFolio.tabs.0.legacyNumber', 'F1203')
        ->has('oldFolio.tabs.0.rows', 0)
        ->has('oldFolio.tabs.0.lines', 5)
        ->where('oldFolio.tabs.0.total', '99.76'));

    $again = $this->post(oldFolioUrl($setup), oldBody([oldRow($setup, OldFolioRowKind::Charge, ['amount' => '5.00'])]));
    $again->assertSessionHasErrors('form');
    expect(FolioLine::query()->where('migrated', true)->count())->toBe(5);
});

test('a date on or after go-live is refused and nothing is posted', function (string $date) {
    $setup = oldFolioSetup();
    $rows = [oldRow($setup, OldFolioRowKind::Room), oldRow($setup, OldFolioRowKind::Charge, ['serviceDate' => $date])];

    $this->actingAs($setup['user'])->post(oldFolioUrl($setup), oldBody($rows))->assertSessionHasErrors('rows.1.service_date');

    expect(FolioLine::query()->where('migrated', true)->count())->toBe(0);
})->with(['go-live day' => '2026-10-10', 'later' => '2026-10-12']);

test('a payment that is refused after the first write rolls everything back', function () {
    $setup = oldFolioSetup();
    $rows = [oldRow($setup, OldFolioRowKind::Room), oldRow($setup, OldFolioRowKind::Payment, ['description' => ' '])];

    $result = app(SaveOldFolios::class)($setup['user'], $setup['reservation'], $rows);

    expect($result->refusal)->toBe(SaveOldFoliosResult::REFUSAL_DESCRIPTION_REQUIRED)
        ->and($result->rowKey)->toBe($rows[1]->key)
        ->and(FolioLine::query()->where('migrated', true)->count())->toBe(0);
});

test('a folio with a live opening balance refuses the copy', function () {
    $setup = oldFolioSetup(OldFolioMode::OneAmount);

    $result = app(SaveOldFolios::class)($setup['user'], $setup['reservation'], [oldRow($setup, OldFolioRowKind::Room)]);

    expect($result->refusal)->toBe('opening_balance_exists')
        ->and(FolioLine::query()->where('migrated', true)->count())->toBe(0);
});

test('a row for a method that is not Old system, or after the window, is refused', function () {
    $setup = oldFolioSetup();
    $cash = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::Cash, 'requires_reference' => false]);

    $payment = app(SaveOldFolios::class)($setup['user'], $setup['reservation'], [oldRow($setup, OldFolioRowKind::Payment, ['paymentMethodId' => $cash->id, 'description' => 'OR-1'])]);
    $setup['property']->settings->forceFill(['migration_window_days' => 0])->save();
    $closed = app(SaveOldFolios::class)($setup['user'], $setup['reservation']->refresh(), [oldRow($setup, OldFolioRowKind::Room)]);

    expect($payment->refusal)->toBe('method_not_available')
        ->and($closed->refusal)->toBe('migration_window_closed')
        ->and(FolioLine::query()->where('migrated', true)->count())->toBe(0);
});

test('a row can sit on another folio, and a Master or third-party folio is added with a tab', function () {
    $setup = oldFolioSetup();
    $url = route('reservations.old-folio.folios.store', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']]);

    $this->actingAs($setup['user'])->post($url)->assertRedirect(oldFolioUrl($setup));
    $this->post($url)->assertRedirect();
    expect(session('inertia.flash_data.toast.type'))->toBe('error');

    $guest = Guest::factory()->create(['property_id' => $setup['property']->id]);
    $this->post($url, ['payer_guest_id' => $guest->id])->assertRedirect(oldFolioUrl($setup));

    $master = Folio::query()->where('reservation_id', $setup['reservation']->id)->where('type', FolioType::Master)->sole();
    $third = Folio::query()->where('reservation_id', $setup['reservation']->id)->where('type', FolioType::ThirdParty)->sole();

    $this->get(oldFolioUrl($setup))->assertInertia(fn (Assert $page) => $page->has('oldFolio.tabs', 3)->where('oldFolio.canAddMaster', false));

    $rows = [oldRow($setup, OldFolioRowKind::Charge, ['folioId' => $master->id]), oldRow($setup, OldFolioRowKind::Charge, ['folioId' => $third->id, 'amount' => '40.00'])];
    $this->post(oldFolioUrl($setup), [...oldBody($rows), 'legacy_numbers' => [$master->id => 'F1204']])->assertRedirect();

    expect(FolioLine::query()->where('folio_id', $master->id)->where('migrated', true)->sole()->amount)->toBe('100.00')
        ->and(FolioLine::query()->where('folio_id', $third->id)->where('migrated', true)->sole()->amount)->toBe('40.00')
        ->and($master->refresh()->legacy_number)->toBe('F1204');
});

test('a folio of another reservation is a 404', function () {
    $setup = oldFolioSetup();
    $other = Folio::factory()->create(['property_id' => $setup['property']->id, 'reservation_id' => Reservation::factory()->create(['property_id' => $setup['property']->id])->id]);

    $this->actingAs($setup['user'])->post(oldFolioUrl($setup), oldBody([oldRow($setup, OldFolioRowKind::Charge, ['folioId' => $other->id])]))->assertNotFound();
});

test('the folio tab links to the old folio page only for folio.manual inside the window on a stay from before go-live', function () {
    $setup = oldFolioSetup();
    $folioTab = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']]);

    $this->actingAs($setup['user'])->get($folioTab)->assertInertia(fn (Assert $page) => $page->where('oldFolioUrl', oldFolioUrl($setup)));

    $setup['property']->settings->forceFill(['migration_window_days' => 0])->save();
    $this->get($folioTab)->assertInertia(fn (Assert $page) => $page->where('oldFolioUrl', null));
});
