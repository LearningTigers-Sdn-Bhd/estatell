<?php

use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\FolioStatusResult;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\ReopenFolio;
use App\Actions\Folio\ReverseLine;
use App\Actions\Folio\TransferLines;
use App\Data\Folio\NewChargeData;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArInvoiceType;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\PermissionEnum;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\ArAllocation;
use App\Models\ArInvoice;
use App\Models\ArPayment;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\Room;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Folio\FolioRefusals;
use App\Services\Folio\FolioReopenRule;
use App\Services\Folio\InHouseUnits;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date, an actor and an open Unit folio that holds one 100.00 charge.
 *
 * @return array{property: Property, actor: User, folio: Folio}
 */
function fcSetup(): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->checkedIn()->create([
        'reservation_id' => Reservation::factory()->create(['property_id' => $property->id])->id,
    ]);
    $actor = User::factory()->create(['property_id' => $property->id]);
    $room = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);

    $charge = app(PostCharge::class)($actor, $unit, NewChargeData::from([
        'chargeItemId' => null,
        'transactionCodeId' => $room->id,
        'description' => null,
        'quantity' => 1,
        'unitAmount' => '100.00',
        'idempotencyKey' => 'manual:'.fake()->uuid(),
    ]))->line();

    return ['property' => $property, 'actor' => $actor, 'folio' => $charge->folio];
}

/** Settles the folio with a payment line, as the payments item will. */
function fcSettle(Folio $folio): void
{
    FolioLine::factory()->create([
        'folio_id' => $folio->id,
        'amount' => '-100.00',
        'unit_amount' => '-100.00',
        'posting_date' => today()->toDateString(),
    ]);
}

test('a settled folio closes, takes an invoice number and logs it', function () {
    $setup = fcSetup();
    fcSettle($setup['folio']);

    $result = app(CloseFolio::class)($setup['actor'], $setup['folio']);
    $folio = $setup['folio']->refresh();

    expect($result->isRefused())->toBeFalse()
        ->and($folio->status)->toBe(FolioStatus::Closed)
        ->and($folio->closed_by)->toBe($setup['actor']->id)
        ->and($folio->closed_at)->not->toBeNull()
        ->and($folio->invoice_number)->not->toBeNull()
        ->and(Activity::query()->where('event', 'folio.closed')->count())->toBe(1);
});

test('closing refuses a folio with a balance and writes nothing', function () {
    $setup = fcSetup();

    $result = app(CloseFolio::class)($setup['actor'], $setup['folio']);

    expect($result->refusal)->toBe(FolioStatusResult::REFUSAL_BALANCE_NOT_ZERO)
        ->and($setup['folio']->refresh()->status)->toBe(FolioStatus::Open)
        ->and($setup['folio']->invoice_number)->toBeNull()
        ->and(Activity::query()->whereIn('event', ['folio.closed', 'folio.reopened'])->count())->toBe(0);
});

test('closing refuses a folio that is not open', function (string $state) {
    $setup = fcSetup();
    $folio = Folio::factory()->{$state}()->create(['property_id' => $setup['property']->id]);

    $result = app(CloseFolio::class)($setup['actor'], $folio);

    expect($result->refusal)->toBe(FolioStatusResult::REFUSAL_NOT_OPEN)
        ->and(Activity::query()->whereIn('event', ['folio.closed', 'folio.reopened'])->count())->toBe(0);
})->with(['closed', 'voided']);

test('closing the same folio twice does not close it again', function () {
    $setup = fcSetup();
    fcSettle($setup['folio']);

    app(CloseFolio::class)($setup['actor'], $setup['folio']);
    $again = app(CloseFolio::class)($setup['actor'], $setup['folio']);

    expect($again->refusal)->toBe(FolioStatusResult::REFUSAL_NOT_OPEN)
        ->and(Activity::query()->where('event', 'folio.closed')->count())->toBe(1);
});

test('reopening a closed folio clears the close fields and keeps the invoice number and the ledger', function () {
    $setup = fcSetup();
    fcSettle($setup['folio']);
    app(CloseFolio::class)($setup['actor'], $setup['folio']);
    $invoice = $setup['folio']->refresh()->invoice_number;

    $result = app(ReopenFolio::class)($setup['actor'], $setup['folio']);
    $folio = $setup['folio']->refresh();

    expect($result->isRefused())->toBeFalse()
        ->and($folio->status)->toBe(FolioStatus::Open)
        ->and($folio->closed_at)->toBeNull()
        ->and($folio->closed_by)->toBeNull()
        ->and($folio->invoice_number)->toBe($invoice)
        ->and($folio->lines()->count())->toBe(2)
        ->and(Activity::query()->where('event', 'folio.reopened')->count())->toBe(1);

    app(CloseFolio::class)($setup['actor'], $folio);

    expect($folio->refresh()->invoice_number)->toBe($invoice);
});

test('reopening refuses an open or voided folio', function (string $state) {
    $setup = fcSetup();
    $folio = $state === 'open' ? $setup['folio'] : Folio::factory()->voided()->create(['property_id' => $setup['property']->id]);

    $result = app(ReopenFolio::class)($setup['actor'], $folio);

    expect($result->refusal)->toBe(FolioStatusResult::REFUSAL_NOT_CLOSED)
        ->and(Activity::query()->where('event', 'folio.reopened')->count())->toBe(0);
})->with(['open', 'voided']);

test('a closed folio refuses new postings until it is reopened', function () {
    $setup = fcSetup();
    fcSettle($setup['folio']);
    app(CloseFolio::class)($setup['actor'], $setup['folio']);

    $post = fn () => app(PostCharge::class)($setup['actor'], $setup['folio']->reservation->units()->first(), NewChargeData::from([
        'chargeItemId' => null,
        'transactionCodeId' => TransactionCode::query()->where('property_id', $setup['property']->id)->value('id'),
        'description' => null,
        'quantity' => 1,
        'unitAmount' => '10.00',
        'idempotencyKey' => 'manual:after-close',
    ]));

    expect($post()->refusal)->toBe('folio_not_open');

    app(ReopenFolio::class)($setup['actor'], $setup['folio']);

    expect($post()->isRefused())->toBeFalse();
});

/**
 * An empty Master folio of the setup's reservation, whose first unit is CheckedIn in room 103 tonight.
 *
 * @param  array{property: Property, actor: User, folio: Folio}  $setup
 */
function fcMasterWithRoom(array $setup): Folio
{
    $unit = ReservationUnit::query()->where('reservation_id', $setup['folio']->reservation_id)->sole();
    $room = Room::factory()->create(['property_id' => $setup['property']->id, 'room_type_id' => $unit->room_type_id, 'number' => '103']);
    UnitNight::factory()->create(['reservation_unit_id' => $unit->id, 'date' => today()->toDateString(), 'room_id' => $room->id]);

    return Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['folio']->reservation_id]);
}

test('closing a Master folio refuses while a unit is checked in, names the room and writes nothing', function () {
    $setup = fcSetup();
    $master = fcMasterWithRoom($setup);

    $result = app(CloseFolio::class)($setup['actor'], $master);

    expect($result->refusal)->toBe(FolioStatusResult::REFUSAL_UNITS_IN_HOUSE)
        ->and($result->rooms)->toBe(['Room 103'])
        ->and(app(FolioRefusals::class)->message((string) $result->refusal, $result->rooms))->toBe('Room 103 is still in-house. Check out all units first.')
        ->and($master->refresh()->status)->toBe(FolioStatus::Open)
        ->and($master->invoice_number)->toBeNull()
        ->and(Activity::query()->whereIn('event', ['folio.closed', 'folio.reopened'])->count())->toBe(0);
});

test('the in-house message lists several rooms and falls back to the room type', function () {
    expect(InHouseUnits::message(['Room 103', 'Room 104']))->toBe('Rooms 103, 104 are still in-house. Check out all units first.')
        ->and(InHouseUnits::message(['Room 103', 'Bed A1']))->toBe('Room 103, Bed A1 are still in-house. Check out all units first.');

    $setup = fcSetup();
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['folio']->reservation_id]);

    expect(app(InHouseUnits::class)->roomsFor($master))->toHaveCount(1)
        ->and(app(InHouseUnits::class)->roomsFor($setup['folio']))->toBe([]);
});

test('a Master folio closes once its units have left, and a Unit folio closes while a unit is in-house', function () {
    $setup = fcSetup();
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['folio']->reservation_id]);
    fcSettle($setup['folio']);

    expect(app(CloseFolio::class)($setup['actor'], $setup['folio'])->isRefused())->toBeFalse();

    ReservationUnit::query()->where('reservation_id', $master->reservation_id)->update(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()]);

    expect(app(CloseFolio::class)($setup['actor'], $master)->isRefused())->toBeFalse()
        ->and($master->refresh()->status)->toBe(FolioStatus::Closed);
});

test('the close route shows the in-house refusal as an error toast', function () {
    $setup = fcSetup();
    $master = fcMasterWithRoom($setup);
    $actor = User::factory()->withRole(DefaultRoleEnum::Owner)->create(['property_id' => $setup['property']->id]);

    $this->actingAs($actor)
        ->post(route('reservations.folios.close', ['property' => $setup['property']->code, 'reservation' => $master->reservation_id, 'folio' => $master->id]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Room 103 is still in-house. Check out all units first.']);
});

/**
 * The setup, with its unit checked out and the unit folio still Open at 100.00.
 *
 * @return array{property: Property, actor: User, folio: Folio}
 */
function fcCheckedOut(): array
{
    $setup = fcSetup();
    ReservationUnit::query()->where('reservation_id', $setup['folio']->reservation_id)->update(['status' => UnitStatus::CheckedOut, 'checked_in_at' => now()->subDay(), 'checked_out_at' => now()]);

    return $setup;
}

test('reversing the last charge of a checked-out unit folio closes it', function () {
    $setup = fcCheckedOut();
    $charge = FolioLine::query()->where('folio_id', $setup['folio']->id)->whereNull('parent_line_id')->sole();

    $result = app(ReverseLine::class)($setup['actor'], $charge);

    expect($result->isRefused())->toBeFalse()
        ->and($setup['folio']->refresh()->status)->toBe(FolioStatus::Closed)
        ->and($setup['folio']->invoice_number)->not->toBeNull()
        ->and(Activity::query()->where('event', 'folio.closed')->count())->toBe(1);
});

test('a transfer that empties a checked-out unit folio closes it and leaves the Master open', function () {
    $setup = fcCheckedOut();
    $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['folio']->reservation_id]);
    $charge = FolioLine::query()->where('folio_id', $setup['folio']->id)->whereNull('parent_line_id')->sole();

    $result = app(TransferLines::class)($setup['actor'], [$charge], $master);

    expect($result->isRefused())->toBeFalse()
        ->and($setup['folio']->refresh()->status)->toBe(FolioStatus::Closed)
        ->and($master->refresh()->status)->toBe(FolioStatus::Open);
});

test('a folio stays open above 0, for a unit that is still in house, and for a Master', function (Closure $arrange) {
    $setup = fcCheckedOut();
    $folio = $arrange($setup);
    $charge = FolioLine::query()->where('folio_id', $folio->id)->whereNull('parent_line_id')->first() ?? FolioLine::query()->where('folio_id', $folio->id)->first();

    app(ReverseLine::class)($setup['actor'], $charge);

    expect($folio->refresh()->status)->toBe(FolioStatus::Open);
})->with([
    'a balance above 0 after the reversal' => [function (array $setup) {
        FolioLine::factory()->create(['folio_id' => $setup['folio']->id, 'amount' => '40.00', 'unit_amount' => '40.00', 'posting_date' => today()->toDateString()]);

        return $setup['folio'];
    }],
    'a unit still in house' => [function (array $setup) {
        ReservationUnit::query()->where('reservation_id', $setup['folio']->reservation_id)->update(['status' => UnitStatus::CheckedIn, 'checked_out_at' => null]);

        return $setup['folio'];
    }],
    'a Master folio' => [function (array $setup) {
        $master = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['folio']->reservation_id]);
        FolioLine::factory()->create(['folio_id' => $master->id, 'amount' => '60.00', 'unit_amount' => '60.00', 'posting_date' => today()->toDateString()]);

        return $master;
    }],
]);

test('a late charge on a checked-out unit with a closed folio is refused with the reopen advice, and posts after reopening', function () {
    $setup = fcCheckedOut();
    $charge = FolioLine::query()->where('folio_id', $setup['folio']->id)->whereNull('parent_line_id')->sole();
    app(ReverseLine::class)($setup['actor'], $charge);
    $unit = ReservationUnit::query()->where('reservation_id', $setup['folio']->reservation_id)->sole();
    $room = TransactionCode::query()->where('system_key', SystemTransactionCode::RoomRevenue->value)->sole();
    $post = fn () => app(PostCharge::class)($setup['actor'], $unit, NewChargeData::from([
        'chargeItemId' => null, 'transactionCodeId' => $room->id, 'description' => null, 'quantity' => 1, 'unitAmount' => '25.00', 'idempotencyKey' => 'manual:'.fake()->uuid(),
    ]));

    $refused = $post();

    expect($refused->refusal)->toBe('folio_not_open')
        ->and(app(FolioRefusals::class)->message((string) $refused->refusal))->toBe('This folio is closed. Reopen it on the folio tab first, then try again.');

    app(ReopenFolio::class)($setup['actor'], $setup['folio']);

    expect($post()->isRefused())->toBeFalse()
        ->and($setup['folio']->refresh()->status)->toBe(FolioStatus::Open);
});

test('who may reopen: folio.reopen any closed folio, check-out staff a unit folio closed this business date', function () {
    $setup = fcCheckedOut();
    $charge = FolioLine::query()->where('folio_id', $setup['folio']->id)->whereNull('parent_line_id')->sole();
    app(ReverseLine::class)($setup['actor'], $charge);
    $folio = $setup['folio']->refresh();
    $rule = app(FolioReopenRule::class);
    setPermissionsTeamId($setup['property']->id);
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create(['property_id' => $setup['property']->id]);
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $setup['property']->id]);
    $housekeeping = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $setup['property']->id]);
    $master = Folio::factory()->master()->closed()->create(['property_id' => $setup['property']->id, 'reservation_id' => $folio->reservation_id]);

    expect($rule->allows($frontDesk, $folio))->toBeTrue()
        ->and($rule->allows($housekeeping, $folio))->toBeFalse()
        ->and($rule->allows($frontDesk, $master))->toBeFalse()
        ->and($rule->allows($manager, $master))->toBeTrue();

    $folio->forceFill(['closed_at' => BusinessDate::query()->sole()->opened_at->subHour()])->save();

    expect($rule->allows($frontDesk, $folio->refresh()))->toBeFalse()
        ->and($rule->allows($manager, $folio))->toBeTrue();
});

test('a closed folio says what to do, by who closed it, when and for whom', function () {
    $setup = fcCheckedOut();
    $charge = FolioLine::query()->where('folio_id', $setup['folio']->id)->whereNull('parent_line_id')->sole();
    app(ReverseLine::class)($setup['actor'], $charge);
    $folio = $setup['folio']->refresh();
    $rule = app(FolioReopenRule::class);
    setPermissionsTeamId($setup['property']->id);
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create(['property_id' => $setup['property']->id]);
    $housekeeping = User::factory()->withRole(DefaultRoleEnum::Housekeeping)->create(['property_id' => $setup['property']->id]);
    $time = $folio->closed_at->setTimezone($setup['property']->settings->time_zone ?? config('app.timezone'))->format('H:i');

    expect($rule->notice($manager, $folio))->toBe('Closed at check-out today, '.$time.'. To add a charge, refund or fix a line, reopen it.')
        ->and($rule->notice($housekeeping, $folio))->toBe('Closed at check-out today, '.$time.'. Ask a manager to reopen it.');

    $folio->forceFill(['closed_at' => $folio->closed_at->subDay()])->save();
    ReservationUnit::query()->where('reservation_id', $folio->reservation_id)->update(['checked_out_at' => now()->subDay()]);

    expect($rule->notice($housekeeping, $folio->refresh()))->toBe('Closed at check-out yesterday. Ask a manager to reopen it.');

    $company = Company::factory()->for($setup['property'])->create(['name' => 'Acme Sdn Bhd']);
    $billed = Folio::factory()->thirdParty()->closed()->create(['property_id' => $setup['property']->id, 'reservation_id' => $folio->reservation_id, 'payer_company_id' => $company->id, 'invoice_number' => 'F-0031']);
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'company_id' => $company->id, 'folio_id' => $billed->id, 'number' => 'F-0031']);
    $byHand = Folio::factory()->master()->closed()->create(['property_id' => $setup['property']->id, 'reservation_id' => $folio->reservation_id]);

    expect($rule->notice($manager, $billed))->toBe('Billed to Acme Sdn Bhd on invoice F-0031. Use a third-party folio or a credit note.')
        ->and($rule->notice($manager, $byHand))->toStartWith('Closed today, ')
        ->and($rule->notice($manager, $setup['folio']->fresh()->forceFill(['status' => FolioStatus::Open])))->toBeNull();
});

test('the folio page sends the notice of a closed folio', function () {
    $setup = fcCheckedOut();
    $owner = User::factory()->withRole(DefaultRoleEnum::Owner)->create(['property_id' => $setup['property']->id]);
    app(ReverseLine::class)($setup['actor'], FolioLine::query()->where('folio_id', $setup['folio']->id)->whereNull('parent_line_id')->sole());

    $this->actingAs($owner)
        ->get(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['folio']->reservation_id, 'folio' => $setup['folio']->id]))
        ->assertInertia(fn ($page) => $page
            ->where('folio.canReopen', true)
            ->where('folio.closedNotice', fn ($notice) => str_starts_with((string) $notice, 'Closed at check-out today, ')));
});

/**
 * A Closed folio billed to a company on an AR invoice, and the folio page URL.
 *
 * @return array{setup: array, invoice: ArInvoice, url: string}
 */
function fcBilledFolio(bool $void = false): array
{
    $setup = fcSetup();
    $company = Company::factory()->for($setup['property'])->create();
    $folio = Folio::factory()->thirdParty()->closed()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['folio']->reservation_id, 'payer_company_id' => $company->id, 'invoice_number' => 'F-0031']);
    $invoice = ($void ? ArInvoice::factory()->void() : ArInvoice::factory())->create(['property_id' => $setup['property']->id, 'company_id' => $company->id, 'folio_id' => $folio->id, 'number' => 'F-0031']);

    return ['setup' => $setup, 'invoice' => $invoice, 'url' => route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $folio->reservation_id, 'folio' => $folio->id])];
}

test('the closed notice links to the invoice and its credit note by permission', function () {
    $billed = fcBilledFolio();
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create(['property_id' => $billed['setup']['property']->id]);
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $billed['setup']['property']->id]);

    $this->actingAs($manager)->get($billed['url'])->assertInertia(fn ($page) => $page
        ->where('folio.arInvoiceId', $billed['invoice']->id)
        ->where('folio.canOpenArInvoice', true)
        ->where('folio.canCreditArInvoice', true)
        ->where('folio.canReopen', false));

    $this->actingAs($frontDesk)->get($billed['url'])->assertInertia(fn ($page) => $page
        ->where('folio.canOpenArInvoice', true)
        ->where('folio.canCreditArInvoice', false));

    setPermissionsTeamId($billed['setup']['property']->id);
    Role::query()->where('property_id', $billed['setup']['property']->id)->where('default_role', DefaultRoleEnum::Manager)->firstOrFail()->revokePermissionTo(PermissionEnum::CompanyView->value);

    $this->actingAs($manager->fresh())->get($billed['url'])->assertInertia(fn ($page) => $page
        ->where('folio.canOpenArInvoice', false)
        ->where('folio.canCreditArInvoice', true));
});

test('a Void invoice gives the closed folio no invoice links', function () {
    $billed = fcBilledFolio(void: true);
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create(['property_id' => $billed['setup']['property']->id]);

    $this->actingAs($manager)->get($billed['url'])->assertInertia(fn ($page) => $page
        ->where('folio.arInvoiceId', null)
        ->where('folio.canOpenArInvoice', false)
        ->where('folio.canCreditArInvoice', false));
});

test('the credit invoice prop loads only when asked and only with the credit note permission', function () {
    $billed = fcBilledFolio();
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create(['property_id' => $billed['setup']['property']->id]);
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create(['property_id' => $billed['setup']['property']->id]);

    $this->actingAs($manager)->get($billed['url'])->assertInertia(fn ($page) => $page->missing('creditInvoice'));

    $this->get($billed['url'])->assertInertia(fn ($page) => $page->reloadOnly('creditInvoice', fn ($reloaded) => $reloaded
        ->where('creditInvoice.row.id', $billed['invoice']->id)
        ->where('creditInvoice.canCreditNote', true)));

    $this->actingAs($frontDesk)->get($billed['url'])->assertInertia(fn ($page) => $page->reloadOnly('creditInvoice', fn ($reloaded) => $reloaded
        ->where('creditInvoice', null)));
});

test('a credit note issued with the folio page as referer returns to the folio', function () {
    $billed = fcBilledFolio();
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create(['property_id' => $billed['setup']['property']->id]);
    $url = "/p/{$billed['setup']['property']->code}/finance/city-ledger/invoices/{$billed['invoice']->id}/credit-notes";

    $this->actingAs($manager)->from($billed['url'])->post($url, ['amount' => '10.00', 'reason' => 'Wrong rate'])->assertRedirect($billed['url']);

    expect(ArInvoice::query()->where('credit_for_invoice_id', $billed['invoice']->id)->count())->toBe(1);
});

test('void and reopen voids the invoice, reopens the folio and keeps the City ledger line', function () {
    $billed = fcBilledFolio();
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create(['property_id' => $billed['setup']['property']->id]);
    $folio = $billed['invoice']->folio;
    $line = FolioLine::factory()->create(['folio_id' => $folio->id, 'idempotency_key' => 'city-ledger:'.$folio->id]);
    $url = route('reservations.folios.void-invoice-and-reopen', ['property' => $billed['setup']['property']->code, 'reservation' => $folio->reservation_id, 'folio' => $folio->id]);

    $this->actingAs($manager)->get($billed['url'])->assertInertia(fn ($page) => $page
        ->where('folio.canVoidAndReopen', true)
        ->where('folio.voidInvoiceBlockedBy', null)
        ->where('folio.arInvoiceNumber', 'F-0031'));

    $this->post($url)->assertRedirect();

    expect($billed['invoice']->fresh()->status)->toBe(ArInvoiceStatus::Void)
        ->and($folio->fresh()->status)->toBe(FolioStatus::Open)
        ->and(FolioLine::query()->whereKey($line->id)->exists())->toBeTrue();
});

test('void and reopen is refused, and rolls back, while a credit note or an allocation stands', function (string $blocker) {
    $billed = fcBilledFolio();
    $property = $billed['setup']['property'];
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create(['property_id' => $property->id]);
    $folio = $billed['invoice']->folio;
    $url = route('reservations.folios.void-invoice-and-reopen', ['property' => $property->code, 'reservation' => $folio->reservation_id, 'folio' => $folio->id]);

    if ($blocker === 'credit note') {
        ArInvoice::factory()->create(['property_id' => $property->id, 'company_id' => $billed['invoice']->company_id, 'type' => ArInvoiceType::CreditNote, 'credit_for_invoice_id' => $billed['invoice']->id, 'folio_id' => null, 'due_on' => null, 'amount' => '-50.00', 'number' => 'CN-0003', 'status' => ArInvoiceStatus::Settled]);
    } else {
        $payment = ArPayment::factory()->create(['property_id' => $property->id, 'company_id' => $billed['invoice']->company_id, 'receipt_number' => 'RC-0045']);
        ArAllocation::factory()->create(['ar_payment_id' => $payment->id, 'ar_invoice_id' => $billed['invoice']->id, 'amount' => '100.00']);
    }

    $this->actingAs($manager)->get($billed['url'])->assertInertia(fn ($page) => $page
        ->where('folio.voidInvoiceBlockedBy', $blocker === 'credit note' ? 'Void credit note CN-0003 first.' : 'Reverse payment RC-0045 first.'));

    $this->post($url)->assertRedirect();

    expect($billed['invoice']->fresh()->status)->toBe(ArInvoiceStatus::Open)
        ->and($folio->fresh()->status)->toBe(FolioStatus::Closed);
})->with(['credit note', 'allocation']);

test('void and reopen needs both city-ledger.invoice and folio.reopen', function () {
    $billed = fcBilledFolio();
    $property = $billed['setup']['property'];
    $folio = $billed['invoice']->folio;
    $url = route('reservations.folios.void-invoice-and-reopen', ['property' => $property->code, 'reservation' => $folio->reservation_id, 'folio' => $folio->id]);

    foreach ([DefaultRoleEnum::FrontDesk, DefaultRoleEnum::Housekeeping] as $role) {
        $user = User::factory()->withRole($role)->create(['property_id' => $property->id]);
        $this->actingAs($user)->post($url)->assertForbidden();
    }

    setPermissionsTeamId($property->id);
    Role::query()->where('property_id', $property->id)->where('default_role', DefaultRoleEnum::Finance)->firstOrFail()->revokePermissionTo(PermissionEnum::FolioReopen->value);
    $finance = User::factory()->withRole(DefaultRoleEnum::Finance)->create(['property_id' => $property->id]);

    $this->actingAs($finance)->post($url)->assertForbidden();

    expect($billed['invoice']->fresh()->status)->toBe(ArInvoiceStatus::Open);
});
