<?php

use App\Actions\Folio\PostManualBatch;
use App\Data\Folio\ManualRowData;
use App\Enums\DefaultRoleEnum;
use App\Enums\ManualRowType;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioManualBatch;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/ManualFolioSetup.php';

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $extra
 */
function mgUrl(array $setup, string $name = 'reservations.folios.manual', array $extra = []): string
{
    return route($name, ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id, ...$extra]);
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function mgRow(array $setup, array $attributes = []): array
{
    return [
        'key' => fake()->uuid(),
        'type' => 'charge',
        'service_date' => '2026-10-11',
        'folio_id' => $setup['folio']->id,
        'unit_id' => $setup['unit']->id,
        'charge_item_id' => $setup['minibar']->id,
        'quantity' => 1,
        'amount' => '6.00',
        ...$attributes,
    ];
}

test('the page opens for a user with folio.manual and lists what the rows pick from', function () {
    $setup = mfSetup();

    $this->actingAs($setup['user'])
        ->withSession(['manual_folio_reason' => 'Paper folio'])
        ->get(mgUrl($setup))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reservations/manual-folio')
            ->where('manual.reason', 'Paper folio')
            ->where('manual.folioId', $setup['folio']->id)
            ->where('manual.businessDate', '2026-10-13')
            ->where('manual.earliestDate', '2026-10-10')
            ->where('manual.itemTaxIds.'.$setup['minibar']->id, [$setup['sst']->id])
            ->where('manual.itemTaxIds.'.$setup['laundry']->id, [])
            ->has('manual.chargeItems', 2)
            ->has('manual.paymentMethods', 1)
            ->has('manual.units', 1)
            ->where('manual.preview.balanceNow', '0.00'));
});

test('the folio rail lists the open folios of the reservation only', function () {
    $setup = mfSetup();
    Folio::factory()->thirdParty()->closed()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);
    $open = Folio::query()->where('reservation_id', $setup['reservation']->id)->where('status', 'open')->pluck('id')->map(fn (int $id): string => (string) $id)->sort()->values()->all();

    $this->actingAs($setup['user'])->get(mgUrl($setup))->assertInertia(fn (Assert $page) => $page
        ->where('manual.rail', fn ($rail): bool => collect($rail)->pluck('key')->sort()->values()->all() === $open)
        ->where('manual.rail.0.balance', '0.00'));
});

test('a page opened without a reason has none', function () {
    $setup = mfSetup();

    $this->actingAs($setup['user'])->get(mgUrl($setup))->assertInertia(fn (Assert $page) => $page->where('manual.reason', null));
});

test('the reason dialog keeps a picked reason as its label', function () {
    $setup = mfSetup();

    $this->actingAs($setup['user'])->post(mgUrl($setup, 'reservations.folios.manual.start'), ['reason_option' => 'missed_charge', 'reason_details' => 'ignored'])
        ->assertRedirect(mgUrl($setup));
    $this->get(mgUrl($setup))->assertInertia(fn (Assert $page) => $page
        ->where('manual.reason', 'A charge was missed')
        ->has('manual.reasonOptions', 6)
        ->where('manual.reasonOptions.5', ['value' => 'other', 'label' => 'Other']));

    expect(FolioManualBatch::query()->count())->toBe(0);
});

test('the reason dialog keeps the details of Other and refuses a missing reason', function () {
    $setup = mfSetup();

    $this->actingAs($setup['user'])->post(mgUrl($setup, 'reservations.folios.manual.start'), ['reason_option' => 'other', 'reason_details' => '  Guest paid cash to the night staff '])
        ->assertRedirect(mgUrl($setup));
    $this->get(mgUrl($setup))->assertInertia(fn (Assert $page) => $page->where('manual.reason', 'Guest paid cash to the night staff'));
    $this->post(mgUrl($setup, 'reservations.folios.manual.start'), ['reason_option' => 'other', 'reason_details' => ''])->assertSessionHasErrors('reason_details');
    $this->post(mgUrl($setup, 'reservations.folios.manual.start'), ['reason_option' => ''])->assertSessionHasErrors('reason_option');
    $this->post(mgUrl($setup, 'reservations.folios.manual.start'), ['reason_option' => 'bored'])->assertSessionHasErrors('reason_option');
});

test('users without folio.manual cannot open, preview or post', function () {
    $setup = mfSetup(DefaultRoleEnum::FrontDesk);

    $this->actingAs($setup['user'])->get(mgUrl($setup))->assertForbidden();
    $this->post(mgUrl($setup, 'reservations.folios.manual.preview'), ['rows' => []])->assertForbidden();
    $this->post(mgUrl($setup, 'reservations.folios.manual.start'), ['reason_option' => 'other', 'reason_details' => 'x'])->assertForbidden();
    $this->post(mgUrl($setup, 'reservations.folios.manual.store'), ['rows' => [mgRow($setup)], 'reason_option' => 'other', 'reason_details' => 'x', 'close' => false, 'idempotency_key' => fake()->uuid()])->assertForbidden();
});

test('another property cannot reach the page', function () {
    $setup = mfSetup();
    $other = User::factory()->withRole(DefaultRoleEnum::Owner)->create();

    $this->actingAs($other)->get(mgUrl($setup))->assertNotFound();
});

test('a closed folio goes back to the folio tab with an error', function () {
    $setup = mfSetup();
    $setup['folio'] = Folio::factory()->thirdParty()->closed()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['reservation']->id]);

    $this->actingAs($setup['user'])->get(mgUrl($setup))->assertRedirect(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]));
});

test('the preview answers for the rows and refuses a folio of another reservation', function () {
    $setup = mfSetup();
    $foreign = Folio::factory()->master()->create(['property_id' => $setup['property']->id]);

    $this->actingAs($setup['user'])->postJson(mgUrl($setup, 'reservations.folios.manual.preview'), ['rows' => [mgRow($setup, ['quantity' => 2])]])
        ->assertOk()
        ->assertJsonPath('rows.0.total', '12.96')
        ->assertJsonPath('balanceAfter', '12.96');
    $this->postJson(mgUrl($setup, 'reservations.folios.manual.preview'), ['rows' => [mgRow($setup, ['folio_id' => $foreign->id])]])->assertNotFound();
    expect(FolioLine::query()->count())->toBe(0);
});

test('posting writes the batch, logs it and opens the folio tab', function () {
    $setup = mfSetup();
    $key = fake()->uuid();
    $body = ['rows' => [mgRow($setup), mgRow($setup, ['type' => 'payment', 'charge_item_id' => null, 'payment_method_id' => $setup['cash']->id, 'amount' => '10.00'])], 'reason_option' => 'other', 'reason_details' => 'Paper folio', 'close' => false, 'idempotency_key' => $key];

    $this->actingAs($setup['user'])->post(mgUrl($setup, 'reservations.folios.manual.store'), $body)
        ->assertRedirect(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]));
    $this->post(mgUrl($setup, 'reservations.folios.manual.store'), $body)->assertRedirect();

    expect(FolioManualBatch::query()->count())->toBe(1)
        ->and(FolioLine::query()->whereNotNull('manual_batch_id')->count())->toBe(2)
        ->and(Activity::query()->where('event', 'folio.manual_batch_posted')->count())->toBe(1);
});

test('a refused row comes back as an error on that row and writes nothing', function () {
    $setup = mfSetup();
    $body = ['rows' => [mgRow($setup), mgRow($setup, ['service_date' => '2026-10-30'])], 'reason_option' => 'other', 'reason_details' => 'Paper folio', 'close' => false, 'idempotency_key' => fake()->uuid()];

    $this->actingAs($setup['user'])->from(mgUrl($setup))->post(mgUrl($setup, 'reservations.folios.manual.store'), $body)
        ->assertRedirect(mgUrl($setup))
        ->assertSessionHasErrors('rows.1.service_date');

    expect(FolioLine::query()->count())->toBe(0)->and(FolioManualBatch::query()->count())->toBe(0);
});

test('the reason is required to post and a picked one is saved as its label', function () {
    $setup = mfSetup();

    $this->actingAs($setup['user'])->post(mgUrl($setup, 'reservations.folios.manual.store'), ['rows' => [mgRow($setup)], 'reason_option' => '', 'close' => false, 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors('reason_option');
    $this->post(mgUrl($setup, 'reservations.folios.manual.store'), ['rows' => [mgRow($setup)], 'reason_option' => 'other', 'reason_details' => ' ', 'close' => false, 'idempotency_key' => fake()->uuid()])
        ->assertSessionHasErrors('reason_details');
    $this->post(mgUrl($setup, 'reservations.folios.manual.store'), ['rows' => [mgRow($setup)], 'reason_option' => 'old_system', 'close' => false, 'idempotency_key' => fake()->uuid()]);

    expect(FolioManualBatch::query()->sole()->reason)->toBe('Moving from the old system');
});

test('the folio tab offers the Manual Folio Editor on an Open folio and marks manual lines only for those who may see them', function () {
    $setup = mfSetup();
    app(PostManualBatch::class)($setup['user'], $setup['reservation'], $setup['folio'], 'Paper folio', [ManualRowData::from(['key' => 'a', 'type' => ManualRowType::Charge, 'serviceDate' => '2026-10-11', 'folioId' => $setup['folio']->id, 'unitId' => $setup['unit']->id, 'chargeItemId' => $setup['laundry']->id, 'quantity' => 1, 'amount' => '25.00'])], false, fake()->uuid());
    $tab = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]);

    $this->actingAs($setup['user'])->get($tab)->assertInertia(fn (Assert $page) => $page
        ->where('folio.canManageManually', true)
        ->where('folio.rows.0.tags.0.value', 'manual')
        ->where('folio.rows.0.tags.0.label', fn (string $label) => str_contains($label, 'Paper folio')));

    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $setup['property']->id]);
    $this->actingAs($frontDesk)->get($tab)->assertInertia(fn (Assert $page) => $page
        ->where('folio.canManageManually', false)
        ->where('folio.rows.0.tags', []));
});
