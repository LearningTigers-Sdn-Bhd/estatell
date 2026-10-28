<?php

use App\Actions\Folio\BillToGuest;
use App\Actions\Folio\PostAdjustment;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\PostingResult;
use App\Actions\Payments\RecordPayment;
use App\Actions\Reservations\AttachGuest;
use App\Data\Folio\NewChargeData;
use App\Data\Payments\NewPaymentData;
use App\Enums\FolioStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\SystemTransactionCode;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date, the Adjustment, Room revenue and Refund codes, a CheckedIn unit with an
 * open unit folio, and a platform admin who belongs to no property.
 *
 * @return array{property: Property, admin: User, staff: User, reservation: Reservation, unit: ReservationUnit, folio: Folio}
 */
function replayHelperSetup(): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);

    foreach ([SystemTransactionCode::Adjustment, SystemTransactionCode::RoomRevenue, SystemTransactionCode::Refund] as $code) {
        TransactionCode::factory()->system($code)->create(['property_id' => $property->id]);
    }

    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $reservation->id]);

    return [
        'property' => $property,
        'admin' => User::factory()->admin()->create(['property_id' => null]),
        'staff' => User::factory()->create(['property_id' => $property->id]),
        'reservation' => $reservation,
        'unit' => $unit,
        'folio' => Folio::factory()->forUnit($unit)->create(['property_id' => $property->id, 'reservation_id' => $reservation->id]),
    ];
}

test('an adjustment posts a signed amount with no tax and a repeat writes nothing', function () {
    $setup = replayHelperSetup();
    $post = fn (string $amount, string $key) => app(PostAdjustment::class)($setup['staff'], $setup['folio'], $amount, 'Room rate adjustment', $key);

    $first = $post('-12.96', 'adj:1');
    $again = $post('-12.96', 'adj:1');
    $raise = $post('76.00', 'adj:2');

    expect($first->isRefused())->toBeFalse()
        ->and($first->lines)->toHaveCount(1)
        ->and($first->line()->amount)->toBe('-12.96')
        ->and($first->line()->transactionCode->system_key)->toBe('adjustment')
        ->and($again->replayed)->toBeTrue()
        ->and($raise->line()->amount)->toBe('76.00')
        ->and($setup['folio']->lines()->count())->toBe(2)
        ->and(Activity::query()->where('event', 'folio.adjustment_posted')->count())->toBe(2);
});

test('an adjustment of zero, a bad amount or a closed folio is refused and writes nothing', function () {
    $setup = replayHelperSetup();
    $post = fn (string $amount, string $key) => app(PostAdjustment::class)($setup['staff'], $setup['folio'], $amount, 'x', $key);

    expect($post('0.00', 'adj:z')->refusal)->toBe(PostingResult::REFUSAL_AMOUNT_INVALID)
        ->and($post('abc', 'adj:a')->refusal)->toBe(PostingResult::REFUSAL_AMOUNT_INVALID);

    $setup['folio']->forceFill(['status' => FolioStatus::Closed])->save();

    expect($post('5.00', 'adj:c')->isRefused())->toBeTrue()
        ->and($setup['folio']->lines()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'folio.adjustment_posted')->count())->toBe(0);
});

test('an admin who belongs to no property can post a charge, staff of another property cannot', function () {
    $setup = replayHelperSetup();
    $other = User::factory()->create();
    $data = fn () => NewChargeData::from([
        'chargeItemId' => null,
        'transactionCodeId' => TransactionCode::query()->where('property_id', $setup['property']->id)->where('system_key', 'room_revenue')->value('id'),
        'description' => null,
        'quantity' => 1,
        'unitAmount' => '30.00',
        'idempotencyKey' => 'manual:'.fake()->uuid(),
    ]);

    expect(app(PostCharge::class)($other, $setup['unit'], $data())->refusal)->toBe(PostingResult::REFUSAL_UNIT_NOT_POSTABLE)
        ->and(app(PostCharge::class)($setup['admin'], $setup['unit'], $data())->isRefused())->toBeFalse();
});

test('an admin can bill a guest of a unit, staff of another property cannot', function () {
    $setup = replayHelperSetup();
    $guest = Guest::factory()->create(['property_id' => $setup['property']->id]);
    app(AttachGuest::class)($setup['staff'], $setup['unit'], $guest);

    expect(app(BillToGuest::class)(User::factory()->create(), $setup['unit'], $guest)->isRefused())->toBeTrue()
        ->and(app(BillToGuest::class)($setup['admin'], $setup['unit'], $guest)->isRefused())->toBeFalse();
});

test('an OTA collected method is refused unless the caller allows it', function () {
    $setup = replayHelperSetup();
    $ota = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::OtaCollected, 'requires_reference' => false]);
    $cash = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::Cash, 'requires_reference' => false]);
    $pay = fn (PaymentMethod $method, bool $allowOtaCollected) => app(RecordPayment::class)($setup['admin'], $setup['reservation'], $setup['folio'], null, NewPaymentData::from([
        'paymentMethodId' => $method->id,
        'purpose' => PaymentPurpose::Settlement,
        'amount' => '80.00',
        'reference' => null,
        'idempotencyKey' => fake()->uuid(),
        'allowOtaCollected' => $allowOtaCollected,
    ]));

    expect($pay($ota, false)->isRefused())->toBeTrue()
        ->and($pay($cash, true)->isRefused())->toBeFalse()
        ->and($pay($ota, true)->isRefused())->toBeFalse()
        ->and($setup['folio']->lines()->count())->toBe(2);
});
