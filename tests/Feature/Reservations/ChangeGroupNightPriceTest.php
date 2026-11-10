<?php

use App\Actions\Folio\PostingResult;
use App\Actions\NightAudit\PostRoomCharges;
use App\Actions\Reservations\ChangeGroupNightPrice;
use App\Actions\Reservations\GroupNightPriceResult;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property trading on 2026-10-12 with an 8% tax on room revenue, and a group of three units at 300.00 a night
 * with different dates: a checked-in unit whose first night is charged (12–14 Oct), and two confirmed units (13–14 Oct
 * and 12–15 Oct).
 *
 * @return array{user: User, property: Property, reservation: Reservation, units: list<ReservationUnit>}
 */
function gnpSetup(DefaultRoleEnum $role = DefaultRoleEnum::Owner): array
{
    test()->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    $room = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);
    TransactionCode::factory()->system(SystemTransactionCode::Discount)->create(['property_id' => $property->id]);
    $tax = Tax::factory()->for($property)->create(['rate' => '8.00', 'rate_type' => TaxRateType::Percent]);
    $tax->applicableTransactionCodes()->attach($room);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $charged = ReservationUnit::factory()->for($reservation)->withNights()->checkedIn()->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14', 'adults' => 2]);
    $short = ReservationUnit::factory()->for($reservation)->withNights()->create(['arrival_date' => '2026-10-13', 'departure_date' => '2026-10-14', 'adults' => 2]);
    $long = ReservationUnit::factory()->for($reservation)->withNights()->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-15', 'adults' => 2]);
    UnitNight::query()->update(['base_amount' => '300.00', 'amount' => '300.00']);
    app(PostRoomCharges::class)(null, $charged, CarbonImmutable::parse('2026-10-12'));

    return ['user' => $user, 'property' => $property, 'reservation' => $reservation, 'units' => [$charged, $short, $long]];
}

/**
 * @param  array{reservation: Reservation, units: list<ReservationUnit>}  $setup
 */
function gnpIds(array $setup): array
{
    return array_map(fn (ReservationUnit $unit): int => $unit->id, $setup['units']);
}

function gnpUrl(array $setup, string $path = 'night-price'): string
{
    return "/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/{$path}";
}

test('each unit gets the price on its own nights and a charged night is charged again', function () {
    $setup = gnpSetup();
    [$charged, $short, $long] = $setup['units'];

    $result = app(ChangeGroupNightPrice::class)($setup['user'], $setup['reservation'], gnpIds($setup), '250.00', 'Agent group rate');

    expect($result->isRefused())->toBeFalse()
        ->and($result->units)->toBe(3)
        ->and($result->changed)->toBe(6)
        ->and($result->corrected)->toBe(1)
        ->and($short->nights()->pluck('override_amount')->all())->toBe(['250.00'])
        ->and($long->nights()->pluck('override_amount')->unique()->all())->toBe(['250.00'])
        ->and($long->nights()->count())->toBe(3);
    $first = $charged->nights()->orderBy('date')->first();
    expect(FolioLine::query()->where('idempotency_key', 'night:'.$first->id.':fix:1')->value('amount'))->toBe('250.00')
        ->and(Activity::query()->where('event', 'reservation.night_price_changed')->count())->toBe(3);
});

test('one refused unit refuses the whole save, names the unit and writes nothing', function () {
    $setup = gnpSetup();
    [$charged, $short] = $setup['units'];
    Folio::query()->update(['status' => FolioStatus::Closed]);
    $lines = FolioLine::query()->count();

    $result = app(ChangeGroupNightPrice::class)($setup['user'], $setup['reservation'], gnpIds($setup), '250.00', 'Agent group rate');

    expect($result->refusal)->toBe(PostingResult::REFUSAL_FOLIO_NOT_OPEN)
        ->and($result->failedUnitId)->toBe($charged->id)
        ->and($result->failedUnit)->toBe($charged->roomType->name.' · Not assigned')
        ->and(FolioLine::query()->count())->toBe($lines)
        ->and($short->nights()->whereNotNull('override_amount')->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'reservation.night_price_changed')->exists())->toBeFalse();
});

test('units of another reservation are ignored and none left is refused', function () {
    $setup = gnpSetup();
    $foreign = ReservationUnit::factory()->withNights()->create(['reservation_id' => Reservation::factory()->create(['property_id' => $setup['property']->id])->id]);

    $result = app(ChangeGroupNightPrice::class)($setup['user'], $setup['reservation'], [$foreign->id], '250.00', 'Agent group rate');

    expect($result->refusal)->toBe(GroupNightPriceResult::REFUSAL_NO_UNITS)
        ->and($foreign->nights()->whereNotNull('override_amount')->exists())->toBeFalse();
});

test('the preview shows each unit with its nights, current price and difference with tax', function () {
    $setup = gnpSetup();

    $this->actingAs($setup['user'])
        ->postJson(gnpUrl($setup, 'night-price/preview'), ['unit_ids' => gnpIds($setup), 'price' => '250.00'])
        ->assertOk()
        ->assertJsonPath('lines.0.nights', 2)
        ->assertJsonPath('lines.0.charged', 1)
        ->assertJsonPath('lines.0.was', '300.00')
        ->assertJsonPath('lines.1.nights', 1)
        ->assertJsonPath('lines.2.nights', 3)
        ->assertJsonPath('difference', '-324.00');

    expect(UnitNight::query()->whereNotNull('override_amount')->exists())->toBeFalse();
});

test('the route changes every unit and names the count in the toast', function () {
    $setup = gnpSetup();

    $this->actingAs($setup['user'])
        ->post(gnpUrl($setup), ['unit_ids' => gnpIds($setup), 'price' => '250.00', 'reason' => 'Agent group rate'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Price changed on 3 units. 1 charged night was charged again.');
});

test('a refused unit comes back as a form error that starts with the unit', function () {
    $setup = gnpSetup();
    Folio::query()->update(['status' => FolioStatus::Closed]);

    $this->actingAs($setup['user'])
        ->post(gnpUrl($setup), ['unit_ids' => gnpIds($setup), 'price' => '250.00', 'reason' => 'Agent group rate'])
        ->assertSessionHasErrors('form');

    expect(session('errors')->first('form'))->toStartWith($setup['units'][0]->roomType->name.' · Not assigned: ');
});

test('front desk gets 403 and another property gets 404', function () {
    $setup = gnpSetup(DefaultRoleEnum::FrontDesk);
    $body = ['unit_ids' => gnpIds($setup), 'price' => '250.00', 'reason' => 'Agent group rate'];

    $this->actingAs($setup['user'])->post(gnpUrl($setup), $body)->assertForbidden();
    $this->actingAs($setup['user'])->postJson(gnpUrl($setup, 'night-price/preview'), $body)->assertForbidden();

    $owner = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $this->actingAs($owner)
        ->post("/p/{$owner->property->code}/reservations/{$setup['reservation']->id}/night-price", $body)
        ->assertNotFound();

    expect(UnitNight::query()->whereNotNull('override_amount')->exists())->toBeFalse();
});
