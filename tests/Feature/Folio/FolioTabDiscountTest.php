<?php

use App\Actions\Folio\CloseFolio;
use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\PostCharge;
use App\Data\Folio\NewChargeData;
use App\Enums\DefaultRoleEnum;
use App\Enums\DiscountScope;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Models\BusinessDate;
use App\Models\Discount;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * A live property with an open business date, an owner, one checked-in unit with a Unit folio, the room revenue
 * code and an 8% tax on it.
 *
 * @return array{user: User, property: Property, reservation: Reservation, unit: ReservationUnit, folio: Folio, room: TransactionCode}
 */
function fxSetup(): array
{
    $user = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $reservation->id]);
    $room = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);
    Tax::factory()->for($property)->create(['rate' => '8.00', 'rate_type' => TaxRateType::Percent, 'is_inclusive' => false])->applicableTransactionCodes()->attach($room);

    return ['user' => $user, 'property' => $property, 'reservation' => $reservation, 'unit' => $unit, 'folio' => app(CreateFolio::class)($reservation, $unit), 'room' => $room];
}

function fxUrl(array $setup, string $route, array $extra = []): string
{
    return route($route, ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id, ...$extra]);
}

function fxCharge(array $setup, string $amount = '100.00'): FolioLine
{
    return app(PostCharge::class)($setup['user'], $setup['unit'], NewChargeData::from([
        'chargeItemId' => null,
        'transactionCodeId' => $setup['room']->id,
        'description' => null,
        'quantity' => 1,
        'unitAmount' => $amount,
        'idempotencyKey' => 'manual:'.fake()->uuid(),
    ]))->line();
}

/** @param array<string, mixed> $state */
function fxDiscount(array $setup, string $kind = 'percent', array $state = []): Discount
{
    $factory = Discount::factory()->for($setup['property']);
    $factory = match ($kind) {
        'fixed' => $factory->fixed(),
        'open' => $factory->open(),
        default => $factory,
    };

    return $factory->create(['scope' => DiscountScope::AllCharges, ...$state]);
}

function fxStaff(array $setup, DefaultRoleEnum $role): User
{
    return User::factory()->withRole($role)->create(['property_id' => $setup['property']->id]);
}

/** @return array{quote: array<string, mixed>, error: string|null} */
function fxQuote(array $setup, Discount $discount, ?string $amount = null, ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? $setup['user'])->postJson(fxUrl($setup, 'reservations.folios.discounts.quote'), ['discount_id' => $discount->id, 'amount' => $amount]);
}

test('a quote lists the eligible charges with the amount and the fingerprint', function () {
    $setup = fxSetup();
    $charge = fxCharge($setup);
    $discount = fxDiscount($setup);

    fxQuote($setup, $discount)
        ->assertOk()
        ->assertJsonPath('error', null)
        ->assertJsonPath('quote.subtotal', '100.00')
        ->assertJsonPath('quote.amount', '10.00')
        ->assertJsonPath('quote.lines.0.folioLineId', $charge->id)
        ->assertJsonPath('quote.lines.0.share', '10.00')
        ->assertJsonStructure(['quote' => ['fingerprint']]);

    expect(FolioLine::query()->count())->toBe(2);
});

test('a quote with nothing eligible or a refused amount answers with a message', function () {
    $setup = fxSetup();
    $discount = fxDiscount($setup);

    fxQuote($setup, $discount)->assertOk()->assertJsonPath('error', 'No charge on this folio can take this discount.');

    fxCharge($setup);
    $fixed = fxDiscount($setup, 'fixed', ['value' => '500.00']);

    fxQuote($setup, $fixed)->assertOk()->assertJsonPath('error', 'Enter an amount greater than 0.');
});

test('applying a quoted discount posts the discount and reduces the tax', function () {
    $setup = fxSetup();
    fxCharge($setup);
    $discount = fxDiscount($setup);
    $fingerprint = fxQuote($setup, $discount)->json('quote.fingerprint');

    $this->actingAs($setup['user'])
        ->post(fxUrl($setup, 'reservations.folios.discounts.store'), ['discount_id' => $discount->id, 'fingerprint' => $fingerprint])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Discount '.$discount->name.' applied.']);

    expect($setup['folio']->lines()->sum('amount'))->toEqual('97.20')
        ->and(Activity::query()->where('event', 'folio.discount_applied')->count())->toBe(1);
});

test('a folio that changed after the quote refuses the discount', function () {
    $setup = fxSetup();
    fxCharge($setup);
    $discount = fxDiscount($setup);
    $fingerprint = fxQuote($setup, $discount)->json('quote.fingerprint');
    fxCharge($setup, '50.00');

    $this->actingAs($setup['user'])
        ->post(fxUrl($setup, 'reservations.folios.discounts.store'), ['discount_id' => $discount->id, 'fingerprint' => $fingerprint])
        ->assertSessionHasErrors('form');

    expect(Activity::query()->where('event', 'folio.discount_applied')->count())->toBe(0);
});

test('an open amount needs the open permission and an unchanged fixed discount does not', function () {
    $setup = fxSetup();
    fxCharge($setup);
    $frontDesk = fxStaff($setup, DefaultRoleEnum::FrontDesk);
    $manager = fxStaff($setup, DefaultRoleEnum::Manager);
    $open = fxDiscount($setup, 'open');
    $fixed = fxDiscount($setup, 'fixed', ['value' => '20.00', 'allow_override' => true]);

    fxQuote($setup, $open, '10.00', $frontDesk)->assertForbidden();
    fxQuote($setup, $fixed, '15.00', $frontDesk)->assertForbidden();
    fxQuote($setup, $fixed, null, $frontDesk)->assertOk()->assertJsonPath('quote.amount', '20.00');
    fxQuote($setup, $fixed, '20.00', $frontDesk)->assertOk();
    fxQuote($setup, $open, '10.00', $manager)->assertOk()->assertJsonPath('quote.amount', '10.00');

    $this->actingAs($frontDesk)
        ->post(fxUrl($setup, 'reservations.folios.discounts.store'), ['discount_id' => $open->id, 'amount' => '10.00', 'fingerprint' => str_repeat('a', 64)])
        ->assertForbidden();

    expect(FolioLine::query()->where('kind', 'adjustment')->count())->toBe(0);
});

test('a discount of another property or an inactive one is a 404 and other folios are out of reach', function () {
    $setup = fxSetup();
    fxCharge($setup);
    $foreign = Discount::factory()->create(['scope' => DiscountScope::AllCharges]);
    $inactive = fxDiscount($setup, 'percent', ['active' => false]);

    fxQuote($setup, $foreign)->assertNotFound();
    fxQuote($setup, $inactive)->assertNotFound();

    $other = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => Reservation::factory()->create(['property_id' => $setup['property']->id])->id]);

    $this->actingAs($setup['user'])
        ->postJson(route('reservations.folios.discounts.quote', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $other->id]), ['discount_id' => fxDiscount($setup)->id])
        ->assertNotFound();
});

test('removing a discount reverses it, and a second try is an error toast', function () {
    $setup = fxSetup();
    fxCharge($setup);
    $discount = fxDiscount($setup);
    $fingerprint = fxQuote($setup, $discount)->json('quote.fingerprint');
    $this->actingAs($setup['user'])->post(fxUrl($setup, 'reservations.folios.discounts.store'), ['discount_id' => $discount->id, 'fingerprint' => $fingerprint]);
    $line = $setup['folio']->lines()->where('kind', 'adjustment')->whereNull('reversal_of_id')->sole();
    $url = fxUrl($setup, 'reservations.folios.discounts.destroy', ['line' => $line->id]);

    $this->actingAs($setup['user'])->delete($url)->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Discount removed.']);

    expect($setup['folio']->lines()->sum('amount'))->toEqual('108.00');

    $this->actingAs($setup['user'])->delete($url)->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This line is already reversed.']);

    expect(Activity::query()->where('event', 'folio.discount_removed')->count())->toBe(1);

    $this->actingAs(fxStaff($setup, DefaultRoleEnum::Housekeeping))->delete($url)->assertForbidden();
});

test('the page offers apply and remove discount only where they can work', function () {
    $setup = fxSetup();
    $charge = fxCharge($setup);
    $discount = fxDiscount($setup);
    $page = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]);

    $this->actingAs($setup['user'])->get($page)->assertInertia(fn (Assert $inertia) => $inertia->where('folio.canApplyDiscount', true));
    $this->actingAs(fxStaff($setup, DefaultRoleEnum::Finance))->get($page)->assertInertia(fn (Assert $inertia) => $inertia->where('folio.canApplyDiscount', true));

    $fingerprint = fxQuote($setup, $discount)->json('quote.fingerprint');
    $this->actingAs($setup['user'])->post(fxUrl($setup, 'reservations.folios.discounts.store'), ['discount_id' => $discount->id, 'fingerprint' => $fingerprint]);

    $this->actingAs($setup['user'])->get($page)->assertInertia(fn (Assert $inertia) => $inertia
        ->where('folio.canApplyDiscount', false)
        ->where('folio.rows', fn ($rows) => collect($rows)->firstWhere('lineId', $charge->id)['actions'] === ['reverse']
            && collect($rows)->filter(fn ($row) => $row['actions'] === ['remove_discount'])->count() === 1
            && collect($rows)->every(fn ($row) => in_array($row['actions'], [[], ['reverse'], ['remove_discount']], true))));

    app(CloseFolio::class)($setup['user'], $setup['folio']->refresh());
});

test('a closed folio and a user without the permission see no discount actions', function () {
    $setup = fxSetup();
    fxCharge($setup);
    $page = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]);

    $this->actingAs(fxStaff($setup, DefaultRoleEnum::Manager))->get($page)->assertInertia(fn (Assert $inertia) => $inertia->where('folio.canApplyDiscount', true));

    $noDiscount = User::factory()->for($setup['property'])->withPermissions(['reservations.view'])->create();

    $this->actingAs($noDiscount)->get($page)->assertInertia(fn (Assert $inertia) => $inertia->where('folio.canApplyDiscount', false));

    $paid = FolioLine::factory()->create(['folio_id' => $setup['folio']->id, 'amount' => '-108.00', 'unit_amount' => '-108.00', 'posting_date' => today()->toDateString()]);
    app(CloseFolio::class)($setup['user'], $setup['folio']);

    $this->actingAs($setup['user'])->get($page)->assertInertia(fn (Assert $inertia) => $inertia->where('folio.canApplyDiscount', false));

    expect($paid->id)->toBeInt();
});

test('the discount options hide open discounts and amount entry from users without the open permission', function () {
    $setup = fxSetup();
    fxCharge($setup);
    fxDiscount($setup, 'open', ['name' => 'Complaint']);
    fxDiscount($setup, 'fixed', ['name' => 'Voucher', 'value' => '20.00', 'allow_override' => true]);
    fxDiscount($setup, 'percent', ['name' => 'Staff rate']);
    $page = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]);
    $partial = fn (): array => ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'reservations/show', 'X-Inertia-Partial-Data' => 'folioDiscounts', 'X-Inertia-Version' => Inertia\Inertia::getVersion()];

    $this->actingAs($setup['user'])->get($page)->assertInertia(fn (Assert $inertia) => $inertia->missing('folioDiscounts'));

    $manager = $this->actingAs($setup['user'])->get($page, $partial())->assertOk()->json('props.folioDiscounts');
    $frontDesk = $this->actingAs(fxStaff($setup, DefaultRoleEnum::FrontDesk))->get($page, $partial())->assertOk()->json('props.folioDiscounts');

    expect(collect($manager)->pluck('name')->all())->toEqualCanonicalizing(['Complaint', 'Voucher', 'Staff rate'])
        ->and(collect($manager)->where('name', 'Voucher')->first()['canEnterAmount'])->toBeTrue()
        ->and(collect($manager)->where('name', 'Staff rate')->first()['canEnterAmount'])->toBeFalse()
        ->and(collect($frontDesk)->pluck('name')->all())->toEqualCanonicalizing(['Voucher', 'Staff rate'])
        ->and(collect($frontDesk)->where('name', 'Voucher')->first()['canEnterAmount'])->toBeFalse();
});
