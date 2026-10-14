<?php

use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\PostingResult;
use App\Actions\NightAudit\PostRoomCharges;
use App\Enums\ArInvoiceStatus;
use App\Enums\ChargingUnit;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\ArInvoice;
use App\Models\BusinessDate;
use App\Models\Discount;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioRoutingRule;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlanInclusion;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use Carbon\CarbonImmutable;

/**
 * A live property trading on 2026-10-12 with the room and discount codes, and a checked-in unit of 2 nights from that
 * date, each night priced at 300.00.
 *
 * @return array{property: Property, reservation: Reservation, unit: ReservationUnit, room: TransactionCode, discountCode: TransactionCode, date: CarbonImmutable}
 */
function nightSetup(string $status = 'checkedIn'): array
{
    test()->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $factory = ReservationUnit::factory()->for($reservation)->withNights();
    $unit = ($status === 'checkedIn' ? $factory->checkedIn() : $factory)->create([
        'arrival_date' => '2026-10-12',
        'departure_date' => '2026-10-14',
        'adults' => 2,
    ]);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => '300.00', 'amount' => '300.00']);

    return [
        'property' => $property,
        'reservation' => $reservation,
        'unit' => $unit,
        'room' => TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]),
        'discountCode' => TransactionCode::factory()->system(SystemTransactionCode::Discount)->create(['property_id' => $property->id]),
        'date' => CarbonImmutable::parse('2026-10-12'),
    ];
}

function nightTax(Property $property, TransactionCode $code, string $rate = '8.00', array $attributes = []): Tax
{
    $tax = Tax::factory()->for($property)->create(['rate' => $rate, 'rate_type' => TaxRateType::Percent, 'name' => 'Service Tax', ...$attributes]);
    $tax->applicableTransactionCodes()->attach($code);

    return $tax;
}

function firstNight(ReservationUnit $unit): UnitNight
{
    return $unit->nights()->orderBy('date')->firstOrFail();
}

test('a night posts its room, discounts and tax on the business date', function () {
    $setup = nightSetup();
    $tax = nightTax($setup['property'], $setup['room']);
    $discount = Discount::factory()->for($setup['property'])->create(['name' => 'Staff rate', 'value' => '10.00']);
    $setup['unit']->forceFill(['discount_id' => $discount->id])->save();
    $night = firstNight($setup['unit']);
    $night->forceFill(['discount_amount' => '48.00', 'amount' => '252.00'])->save();

    $result = app(PostRoomCharges::class)(null, $setup['unit'], $setup['date']);

    expect($result->isRefused())->toBeFalse()->and($result->replayed)->toBeFalse();
    $lines = $result->lines;
    expect(collect($lines)->map(fn (FolioLine $line) => [$line->idempotency_key, $line->kind, $line->amount])->all())->toBe([
        ['night:'.$night->id, TransactionKind::Charge, '300.00'],
        ['night:'.$night->id.':stay-discount', TransactionKind::Adjustment, '-48.00'],
        ['night:'.$night->id.':discount', TransactionKind::Adjustment, '-25.20'],
        ['night:'.$night->id.':tax:'.$tax->id, TransactionKind::Tax, '18.14'],
    ]);
    expect($lines[0]->source_id)->toBe($night->id)->and($lines[0]->source_type)->toBe($night->getMorphClass())
        ->and($lines[0]->user_id)->toBeNull()
        ->and($lines[1]->transaction_code_id)->toBe($setup['discountCode']->id)
        ->and($lines[2]->transaction_code_id)->toBe($discount->transaction_code_id)
        ->and($lines[1]->parent_line_id)->toBe($lines[0]->id)
        ->and($lines[3]->parent_line_id)->toBe($lines[0]->id)
        ->and($lines[0]->posting_date->toDateString())->toBe('2026-10-12');
    expect(Folio::query()->where('reservation_unit_id', $setup['unit']->id)->count())->toBe(1);
});

test('inclusions split off the room and post as their own charges', function () {
    $setup = nightSetup();
    nightTax($setup['property'], $setup['room']);
    $inclusion = RatePlanInclusion::factory()->create(['rate_plan_id' => $setup['unit']->rate_plan_id, 'charging_unit' => ChargingUnit::PerGuestNight, 'allocated_amount' => '25.00']);
    $night = firstNight($setup['unit']);

    $result = app(PostRoomCharges::class)(null, $setup['unit'], $setup['date']);

    expect(collect($result->lines)->map(fn (FolioLine $line) => [$line->idempotency_key, $line->amount])->all())->toBe([
        ['night:'.$night->id, '250.00'],
        ['night:'.$night->id.':tax:'.Tax::query()->value('id'), '20.00'],
        ['night:'.$night->id.':incl:'.$inclusion->id, '50.00'],
    ]);
    expect($result->lines[2]->transaction_code_id)->toBe($inclusion->chargeItem->transaction_code_id)
        ->and($result->lines[2]->source_id)->toBe($night->id)->and($result->lines[2]->source_type)->toBe($night->getMorphClass());
});

test('a per stay inclusion posts on the first night only', function () {
    $setup = nightSetup();
    RatePlanInclusion::factory()->create(['rate_plan_id' => $setup['unit']->rate_plan_id, 'charging_unit' => ChargingUnit::PerStay, 'allocated_amount' => '40.00']);

    app(PostRoomCharges::class)(null, $setup['unit'], $setup['date']);
    BusinessDate::query()->update(['date' => '2026-10-13']);
    app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse('2026-10-13'));

    expect(FolioLine::query()->where('idempotency_key', 'like', '%:incl:%')->count())->toBe(1)
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'night:%')->whereNull('parent_line_id')->count())->toBe(3);
});

test('an overridden night posts one line with no discount children', function () {
    $setup = nightSetup();
    firstNight($setup['unit'])->forceFill(['override_amount' => '250.00', 'discount_amount' => '10.00', 'amount' => '250.00'])->save();

    $result = app(PostRoomCharges::class)(null, $setup['unit'], $setup['date']);

    expect($result->lines)->toHaveCount(1)->and($result->lines[0]->amount)->toBe('250.00');
});

test('running a night twice writes nothing the second time', function () {
    $setup = nightSetup();
    nightTax($setup['property'], $setup['room']);

    $first = app(PostRoomCharges::class)(null, $setup['unit'], $setup['date']);
    $count = FolioLine::query()->count();
    $second = app(PostRoomCharges::class)(null, $setup['unit'], $setup['date']);

    expect($second->replayed)->toBeTrue()->and($second->lines)->toHaveCount(count($first->lines))
        ->and(FolioLine::query()->count())->toBe($count)
        ->and(Folio::query()->count())->toBe(1);
});

test('a routing rule sends the room to the master folio', function () {
    $setup = nightSetup();
    $master = app(CreateFolio::class)($setup['reservation'], null);
    FolioRoutingRule::factory()->create(['reservation_id' => $setup['reservation']->id, 'reservation_unit_id' => null, 'transaction_code_id' => $setup['room']->id, 'category' => null, 'target_folio_id' => $master->id]);

    $result = app(PostRoomCharges::class)(null, $setup['unit'], $setup['date']);

    expect($result->line()->folio_id)->toBe($master->id)
        ->and(Folio::query()->where('type', FolioType::Unit)->count())->toBe(0);
});

test('a closed target folio refuses the night and writes nothing', function () {
    $setup = nightSetup();
    $folio = app(CreateFolio::class)($setup['reservation'], $setup['unit']);
    $folio->forceFill(['status' => FolioStatus::Closed])->save();

    $result = app(PostRoomCharges::class)(null, $setup['unit'], $setup['date']);

    expect($result->refusal)->toBe(PostingResult::REFUSAL_FOLIO_NOT_OPEN)->and(FolioLine::query()->count())->toBe(0);
});

test('a target folio settled to the city ledger refuses the night with its own code, and a void invoice does not', function () {
    $setup = nightSetup();
    $folio = app(CreateFolio::class)($setup['reservation'], $setup['unit']);
    $folio->forceFill(['status' => FolioStatus::Closed])->save();
    $invoice = ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'folio_id' => $folio->id]);

    $result = app(PostRoomCharges::class)(null, $setup['unit'], $setup['date']);

    expect($result->refusal)->toBe(PostingResult::REFUSAL_FOLIO_SETTLED)->and(FolioLine::query()->count())->toBe(0);

    $invoice->update(['status' => ArInvoiceStatus::Void]);

    expect(app(PostRoomCharges::class)(null, $setup['unit'], $setup['date'])->refusal)->toBe(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
});

test('a refused tax writes nothing', function () {
    $setup = nightSetup();
    nightTax($setup['property'], $setup['room'], '10.00', ['foreign_guests_only' => true]);
    Guest::query()->whereKey($setup['reservation']->booker_guest_id)->update(['nationality_code' => null]);

    $result = app(PostRoomCharges::class)(null, $setup['unit'], $setup['date']);

    expect($result->refusal)->toBe('nationality_required')->and(FolioLine::query()->count())->toBe(0)->and(Folio::query()->count())->toBe(0);
});

test('discounts that exceed the room refuse the night', function () {
    $setup = nightSetup();
    RatePlanInclusion::factory()->create(['rate_plan_id' => $setup['unit']->rate_plan_id, 'charging_unit' => ChargingUnit::PerNight, 'allocated_amount' => '280.00']);
    firstNight($setup['unit'])->forceFill(['discount_amount' => '30.00', 'amount' => '270.00'])->save();

    $result = app(PostRoomCharges::class)(null, $setup['unit'], $setup['date']);

    expect($result->refusal)->toBe('discount_exceeds_room')->and(FolioLine::query()->count())->toBe(0);
});

test('only a checked-in unit on the business date posts', function () {
    $setup = nightSetup('confirmed');
    $post = app(PostRoomCharges::class);

    expect($post(null, $setup['unit'], $setup['date'])->refusal)->toBe(PostingResult::REFUSAL_UNIT_NOT_POSTABLE);

    $setup['unit']->forceFill(['status' => UnitStatus::CheckedIn, 'checked_in_at' => now()])->save();

    expect($post(null, $setup['unit'], CarbonImmutable::parse('2026-10-13'))->refusal)->toBe(PostingResult::REFUSAL_NO_BUSINESS_DATE);

    UnitNight::query()->where('reservation_unit_id', $setup['unit']->id)->delete();

    expect($post(null, $setup['unit'], $setup['date'])->refusal)->toBe(PostingResult::REFUSAL_NIGHT_NOT_FOUND)->and(FolioLine::query()->count())->toBe(0);
});
