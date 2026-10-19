<?php

require_once __DIR__.'/../Support/MigratedStaySetup.php';

use App\Actions\Folio\AddOldFolio;
use App\Actions\Folio\ChangeOpeningBalance;
use App\Actions\Folio\PostOpeningBalance;
use App\Actions\Folio\PrepareOldFolios;
use App\Actions\Folio\SaveOldFolios;
use App\Actions\Folio\SaveOldFoliosResult;
use App\Actions\Folio\TransferLines;
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
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use App\Services\Folio\OldFolioLines;
use App\Services\Folio\OldFolioPage;
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
    TransactionCode::factory()->system(SystemTransactionCode::Refund)->create(['property_id' => $property->id]);
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
    $nights = $setup['unit']->nights()->orderBy('date')->pluck('id');

    return [
        oldRow($setup, OldFolioRowKind::Room, ['key' => OldFolioPage::nightKey($nights[0])]),
        oldRow($setup, OldFolioRowKind::Room, ['key' => OldFolioPage::nightKey($nights[1]), 'serviceDate' => '2026-10-09', 'amount' => '120.00']),
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
        ->where('oldFolio.tabs.0.openingBalance', null)
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

test('after a save the page opens with the saved lines as rows and drafts only the nights never saved', function () {
    $setup = oldFolioSetup();
    app(SaveOldFolios::class)($setup['user'], $setup['reservation'], oldRows($setup), [$setup['folio']->id => 'F1203']);

    $this->actingAs($setup['user'])->get(oldFolioUrl($setup))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('oldFolio.tabs.0.legacyNumber', 'F1203')
        ->has('oldFolio.tabs.0.rows', 5)
        ->where('oldFolio.tabs.0.rows', function ($rows) use ($setup) {
            $rows = collect($rows);
            $by = fn (string $kind) => $rows->firstWhere('kind', $kind);

            return $rows->every(fn ($row) => $row['lineId'] !== null)
                && $rows->where('kind', 'room')->count() === 2
                && $by('charge')['chargeItemId'] === $setup['minibar']->id && $by('charge')['unitId'] === null && $by('charge')['quantity'] === 2 && $by('charge')['amount'] === '6.00'
                && $by('tax')['taxId'] === $setup['sst']->id
                && $by('payment')['paymentMethodId'] === $setup['old']->id && $by('payment')['amount'] === '150.00' && $by('payment')['description'] === 'OR-1203';
        }));
});

test('opening the page writes nothing, and a unit without a folio gets a tab whose folio is made on save', function () {
    $setup = oldFolioSetup();
    $unit = ReservationUnit::factory()->for($setup['reservation'])->withNights()->create(['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'arrival_date' => '2026-10-01', 'departure_date' => '2026-10-03', 'status' => UnitStatus::CheckedIn, 'checked_in_at' => '2026-10-01 15:00:00']);
    $folios = Folio::query()->count();
    $lines = FolioLine::query()->count();

    $this->actingAs($setup['user'])->get(oldFolioUrl($setup))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('oldFolio.tabs', 2)
        ->where('oldFolio.tabs.1.folioId', -$unit->id)
        ->where('oldFolio.tabs.1.rows', fn ($rows) => count($rows) === 2 && collect($rows)->every(fn ($row) => $row['folioId'] === -$unit->id && $row['unitId'] === $unit->id)));

    expect(Folio::query()->count())->toBe($folios)->and(FolioLine::query()->count())->toBe($lines);

    $row = oldRow($setup, OldFolioRowKind::Room, ['folioId' => -$unit->id, 'unitId' => $unit->id]);
    $this->post(oldFolioUrl($setup), [...oldBody([$row]), 'legacy_numbers' => [-$unit->id => 'F2000']])->assertRedirect();

    $created = Folio::query()->where('reservation_unit_id', $unit->id)->where('type', FolioType::Unit)->sole();
    expect(Folio::query()->count())->toBe($folios + 1)
        ->and($created->legacy_number)->toBe('F2000')
        ->and(FolioLine::query()->where('folio_id', $created->id)->where('migrated', true)->sole()->idempotency_key)->toBe('migrated:'.$created->id.':'.$row->key);

    $this->post(oldFolioUrl($setup), oldBody([$row]))->assertRedirect()->assertSessionHasNoErrors();
    expect(Folio::query()->count())->toBe($folios + 1)->and(FolioLine::query()->where('folio_id', $created->id)->count())->toBe(1);
});

test('a copied migrated stay gets its unit folio when it is saved, so the page has a tab', function () {
    $setup = oldFolioSetup();

    expect(Folio::query()->where('reservation_id', $setup['reservation']->id)->where('type', FolioType::Unit)->count())->toBe(1);
});

function oldSave(array $setup, array $rows)
{
    return app(SaveOldFolios::class)($setup['user'], $setup['reservation']->refresh(), $rows);
}

function oldLine(array $setup, string $key, int $version = 0, ?int $folioId = null): FolioLine
{
    return FolioLine::query()->where('idempotency_key', 'migrated:'.($folioId ?? $setup['folio']->id).':'.$key.($version > 0 ? '~'.$version : ''))->firstOrFail();
}

test('a changed row reverses its line and posts a new one, a removed row is reversed and a new row posts', function () {
    $setup = oldFolioSetup();
    $rows = oldRows($setup);
    expect(oldSave($setup, $rows)->refusal)->toBeNull();
    $first = oldLine($setup, $rows[0]->key);
    $charge = oldLine($setup, $rows[2]->key);
    $count = FolioLine::query()->count();

    $edited = [oldRow($setup, OldFolioRowKind::Room, ['key' => $rows[0]->key, 'amount' => '110.00']), $rows[1], $rows[3], $rows[4], oldRow($setup, OldFolioRowKind::Charge, ['amount' => '8.00', 'description' => 'Water'])];
    $result = oldSave($setup, $edited);

    expect($result->refusal)->toBeNull()->and($result->lineCount)->toBe(2)->and($result->changedCount)->toBe(1)->and($result->removedCount)->toBe(1)
        ->and(FolioLine::query()->count())->toBe($count + 4);

    $reversal = FolioLine::query()->where('reversal_of_id', $first->id)->sole();
    $replacement = oldLine($setup, $rows[0]->key, 1);
    expect((string) $reversal->amount)->toBe('-100.00')->and($reversal->migrated)->toBeTrue()->and($reversal->posting_date->toDateString())->toBe('2026-10-14')
        ->and((string) $replacement->amount)->toBe('110.00')->and($replacement->migrated)->toBeTrue()->and($replacement->service_date->toDateString())->toBe('2026-10-08')
        ->and((string) FolioLine::query()->where('reversal_of_id', $charge->id)->sole()->amount)->toBe('-12.00')
        ->and(FolioLine::query()->where('reversal_of_id', oldLine($setup, $rows[1]->key)->id)->exists())->toBeFalse()
        ->and(FolioLine::query()->where('reversal_of_id', oldLine($setup, $rows[3]->key)->id)->exists())->toBeFalse()
        ->and(FolioLine::query()->where('folio_id', $setup['folio']->id)->where('description', 'Water')->sole()->migrated)->toBeTrue()
        ->and(app(FolioBalance::class)->forFolio($setup['folio']))->toBe('505.76');

    $again = oldSave($setup, $edited);
    expect($again->replayed)->toBeTrue()->and(FolioLine::query()->count())->toBe($count + 4);

    oldSave($setup, [oldRow($setup, OldFolioRowKind::Room, ['key' => $rows[0]->key, 'amount' => '120.00']), ...array_slice($edited, 1)]);
    expect(oldLine($setup, $rows[0]->key, 2)->amount)->toBe('120.00')->and(FolioLine::query()->where('reversal_of_id', $replacement->id)->exists())->toBeTrue();
});

test('the edit goes through the page request and logs the counts', function () {
    $setup = oldFolioSetup();
    $rows = oldRows($setup);
    $this->actingAs($setup['user'])->post(oldFolioUrl($setup), oldBody($rows))->assertRedirect();

    $this->post(oldFolioUrl($setup), oldBody([$rows[0]]))->assertRedirect(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']]))->assertSessionHasNoErrors();

    expect(FolioLine::query()->whereNotNull('reversal_of_id')->where('migrated', true)->count())->toBe(3)
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(1)
        ->and(session('inertia.flash_data.toast.message'))->toBe('4 lines removed.');

    $log = Activity::query()->where('event', 'reservation.old_folios_saved')->latest('id')->first();
    expect($log->properties['removed'])->toBe(4)->and($log->properties['lines'])->toBe(0)->and($log->properties['changed'])->toBe(0);

    $this->post(oldFolioUrl($setup), oldBody([]))->assertSessionHasNoErrors();
    expect(FolioLine::query()->whereNotNull('reversal_of_id')->where('migrated', true)->count())->toBe(4);
});

test('a changed or removed Old system payment is refunded to Old system, and a changed one is recorded again', function () {
    $setup = oldFolioSetup();
    $rows = oldRows($setup);
    oldSave($setup, $rows);
    $original = Payment::query()->sole();
    $paymentLine = FolioLine::query()->findOrFail($original->folio_line_id);

    $changed = [...array_slice($rows, 0, 4), oldRow($setup, OldFolioRowKind::Payment, ['key' => $rows[4]->key, 'amount' => '200.00', 'description' => 'OR-1203', 'serviceDate' => '2026-10-09'])];
    expect(oldSave($setup, $changed)->refusal)->toBeNull();

    $refund = Payment::query()->whereNotNull('refund_of_id')->sole();
    $refundLine = FolioLine::query()->findOrFail($refund->folio_line_id);
    $replacement = Payment::query()->where('folio_line_id', oldLine($setup, $rows[4]->key, 1)->id)->sole();

    expect($refund->refund_of_id)->toBe($original->id)->and((string) $refund->amount)->toBe('150.00')->and($refund->paymentMethod->type)->toBe(PaymentMethodType::OldSystem)
        ->and($refundLine->migrated)->toBeTrue()->and($refundLine->transactionCode->system_key)->toBe(SystemTransactionCode::Refund->value)
        ->and($refundLine->reversal_of_id)->toBeNull()
        ->and((string) $replacement->amount)->toBe('200.00')->and($replacement->receipt_number)->toBeNull()->and($replacement->reference)->toBe('OR-1203')
        ->and(FolioLine::query()->where('reversal_of_id', $paymentLine->id)->exists())->toBeFalse()
        ->and(app(FolioBalance::class)->forFolio($setup['folio']))->toBe(number_format(400 + 100 + 120 + 12 + 17.76 - 200, 2, '.', ''));

    expect(oldSave($setup, $changed)->replayed)->toBeTrue();

    expect(oldSave($setup, array_slice($changed, 0, 4))->removedCount)->toBe(1)
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(2)
        ->and(app(FolioBalance::class)->forFolio($setup['folio']))->toBe(number_format(400 + 100 + 120 + 12 + 17.76, 2, '.', ''));

    $this->actingAs($setup['user'])->get(oldFolioUrl($setup))->assertInertia(fn (Assert $page) => $page->where('oldFolio.tabs.0.rows', fn ($rows) => collect($rows)->where('kind', 'payment')->isEmpty()));
});

test('a row moved to another folio reverses on the first and posts on the second', function () {
    $setup = oldFolioSetup();
    $rows = oldRows($setup);
    oldSave($setup, $rows);
    $master = app(AddOldFolio::class)($setup['user'], $setup['reservation'], null, null);

    $moved = [...array_slice($rows, 0, 2), oldRow($setup, OldFolioRowKind::Charge, ['key' => $rows[2]->key, 'folioId' => $master->id, 'quantity' => 2, 'amount' => '6.00', 'description' => '2 x Coke']), ...array_slice($rows, 3)];
    expect(oldSave($setup, $moved)->refusal)->toBeNull();

    $old = oldLine($setup, $rows[2]->key);
    expect(FolioLine::query()->where('reversal_of_id', $old->id)->exists())->toBeTrue()
        ->and(oldLine($setup, $rows[2]->key, 1, $master->id)->folio_id)->toBe($master->id);
});

test('after the window the page is read only: a new save is refused and nothing changes', function () {
    $setup = oldFolioSetup();
    $rows = oldRows($setup);
    oldSave($setup, $rows);
    $setup['property']->settings->forceFill(['migration_window_days' => 0])->save();
    $count = FolioLine::query()->count();

    $result = oldSave($setup, [oldRow($setup, OldFolioRowKind::Room, ['key' => $rows[0]->key, 'amount' => '999.00'])]);

    expect($result->refusal)->toBe('migration_window_closed')->and(FolioLine::query()->count())->toBe($count);
    $this->actingAs($setup['user'])->get(oldFolioUrl($setup))->assertRedirect();
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

test('saving lines on a folio with an opening balance reverses the opening balance in the same save', function () {
    $setup = oldFolioSetup(OldFolioMode::OneAmount);
    $opening = FolioLine::query()->where('idempotency_key', 'opening:'.$setup['folio']->id.':1')->sole();

    $this->actingAs($setup['user'])->get(oldFolioUrl($setup))->assertInertia(fn (Assert $page) => $page->where('oldFolio.tabs.0.openingBalance', '1080.00'));

    $result = app(SaveOldFolios::class)($setup['user'], $setup['reservation'], [oldRow($setup, OldFolioRowKind::Room)]);

    expect($result->refusal)->toBeNull()
        ->and(FolioLine::query()->where('reversal_of_id', $opening->id)->sole()->amount)->toBe('-1080.00')
        ->and(PostOpeningBalance::liveLine($setup['folio'], PostOpeningBalance::code($setup['property']->id)))->toBeNull()
        ->and(app(FolioBalance::class)->forFolio($setup['folio']))->toBe('500.00')
        ->and(Activity::query()->where('event', 'reservation.old_folios_saved')->sole()->properties['opening_balances_reversed'])->toBe(1);
});

test('posting an opening balance on a folio with copied lines takes the copied lines back first', function () {
    $setup = oldFolioSetup();
    oldSave($setup, oldRows($setup));
    $unit = $setup['unit'];

    $result = app(PostOpeningBalance::class)($setup['user'], $unit, '500.00', 'F9');

    expect($result->refusal)->toBeNull()
        ->and(app(OldFolioLines::class)->live($setup['property']->id, [$setup['folio']->id]))->toBe([])
        ->and(FolioLine::query()->where('folio_id', $setup['folio']->id)->where('migrated', true)->whereNotNull('reversal_of_id')->count())->toBe(4)
        ->and(Payment::query()->whereNotNull('refund_of_id')->count())->toBe(1)
        ->and(app(FolioBalance::class)->forFolio($setup['folio']))->toBe('900.00')
        ->and((string) PostOpeningBalance::liveLine($setup['folio'], PostOpeningBalance::code($setup['property']->id))->amount)->toBe('500.00');
});

test('changing an opening balance also takes copied lines back', function () {
    $setup = oldFolioSetup(OldFolioMode::OneAmount);
    $opening = FolioLine::query()->where('idempotency_key', 'opening:'.$setup['folio']->id.':1')->sole();
    $key = (string) Str::uuid();
    FolioLine::factory()->create(['folio_id' => $setup['folio']->id, 'transaction_code_id' => $opening->transaction_code_id, 'kind' => TransactionKind::Charge, 'amount' => '40.00', 'unit_amount' => '40.00', 'migrated' => true, 'service_date' => '2026-10-08', 'posting_date' => '2026-10-14', 'idempotency_key' => 'migrated:'.$setup['folio']->id.':'.$key]);

    $result = app(ChangeOpeningBalance::class)($setup['user'], $opening, '700.00', 'Corrected');

    expect($result->refusal)->toBeNull()
        ->and(FolioLine::query()->where('idempotency_key', 'migrated:'.$setup['folio']->id.':'.$key)->sole()->reversal()->exists())->toBeTrue()
        ->and(app(FolioBalance::class)->forFolio($setup['folio']))->toBe('1100.00');
});

test('Move charges keeps migrated on the copy and on the reversal', function () {
    $setup = oldFolioSetup();
    $rows = oldRows($setup);
    oldSave($setup, $rows);
    $master = app(AddOldFolio::class)($setup['user'], $setup['reservation'], null, null);
    $charge = oldLine($setup, $rows[2]->key);

    expect(app(TransferLines::class)($setup['user'], [$charge], $master)->refusal)->toBeNull();

    $copy = FolioLine::query()->where('folio_id', $master->id)->sole();
    expect($copy->migrated)->toBeTrue()->and($copy->idempotency_key)->toStartWith('transfer:')
        ->and(FolioLine::query()->where('reversal_of_id', $charge->id)->sole()->migrated)->toBeTrue();
});

test('the folio tab shows the OLD SYSTEM section only for a folio with migrated lines', function () {
    $setup = oldFolioSetup();
    $tab = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']]);

    $this->actingAs($setup['user'])->get($tab)->assertInertia(fn (Assert $page) => $page->where('folio.oldSystemTitle', null)->where('folio.oldSystemTotal', null));

    $rows = oldRows($setup);
    oldSave($setup, $rows);
    $this->get($tab)->assertInertia(fn (Assert $page) => $page
        ->where('folio.oldSystemTitle', 'OLD SYSTEM')
        ->where('folio.oldSystemTotal', '99.76')
        ->where('folio.canManageManually', true)
        ->where('oldFolioUrl', oldFolioUrl($setup))
        ->where('folio.rows', fn ($all) => collect($all)->pluck('state')->take(5)->unique()->all() === ['old'] && collect($all)->where('state', 'old')->count() === 5 && collect($all)->where('state', 'posted')->every(fn ($row) => ! str_contains($row['description'], 'Paid in old system'))));

    $setup['folio']->forceFill(['legacy_number' => 'F1203'])->save();
    oldSave($setup, [$rows[0]]);
    $this->get($tab)->assertInertia(fn (Assert $page) => $page
        ->where('folio.oldSystemTitle', 'OLD SYSTEM · folio F1203')
        ->where('folio.rows', fn ($all) => collect($all)->where('state', 'old')->pluck('tags')->flatten(1)->pluck('value')->unique()->sort()->values()->all() === ['refund', 'reversal', 'reversed']));

    $clerk = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::ReservationsView])->create();
    $this->actingAs($clerk)->get($tab)->assertInertia(fn (Assert $page) => $page->where('oldFolioUrl', null)->where('folio.canManageManually', false));
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
