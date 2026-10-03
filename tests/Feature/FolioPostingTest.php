<?php

use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\PostingResult;
use App\Actions\Folio\ReverseLine;
use App\Actions\Folio\TransferLines;
use App\Data\Folio\NewChargeData;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\TransactionKind;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitAddOn;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use App\Services\Folio\FolioRouter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date (today), a CheckedIn unit, an actor and an active charge item of 50.00
 * that allows overrides.
 *
 * @param  array<string, mixed>  $itemAttributes
 * @return array{property: Property, actor: User, unit: ReservationUnit, item: ChargeItem}
 */
function postingSetup(array $itemAttributes = []): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $reservation->id]);
    $item = ChargeItem::factory()->create(['property_id' => $property->id, 'default_amount' => '50.00', 'allow_override' => true, ...$itemAttributes]);

    return ['property' => $property, 'actor' => User::factory()->create(['property_id' => $property->id]), 'unit' => $unit, 'item' => $item];
}

/** @param array<string, mixed> $attributes */
function chargeData(ChargeItem $item, array $attributes = []): NewChargeData
{
    return NewChargeData::from([
        'chargeItemId' => $item->id,
        'transactionCodeId' => null,
        'description' => null,
        'quantity' => 1,
        'unitAmount' => '50.00',
        'idempotencyKey' => 'manual:'.fake()->uuid(),
        ...$attributes,
    ]);
}

function addTax(Property $property, TransactionCode $code, string $rate = '8.00', bool $inclusive = false): Tax
{
    $tax = Tax::factory()->for($property)->create(['rate' => $rate, 'is_inclusive' => $inclusive]);
    $tax->applicableTransactionCodes()->attach($code);

    return $tax;
}

function balanceOf(Folio $folio): string
{
    return app(FolioBalance::class)->forFolio($folio);
}

/**
 * Posts a taxed charge of 100.00 (8% tax) and returns the charge line.
 *
 * @param  array{property: Property, actor: User, unit: ReservationUnit, item: ChargeItem}  $setup
 */
function postTaxedCharge(array $setup, int $quantity = 2): FolioLine
{
    addTax($setup['property'], $setup['item']->transactionCode);

    return app(PostCharge::class)($setup['actor'], $setup['unit'], chargeData($setup['item'], ['quantity' => $quantity]))->line();
}

test('a charge posts with a tax child, signed amounts and the business date, and creates the unit folio', function () {
    $setup = postingSetup();
    $tax = addTax($setup['property'], $setup['item']->transactionCode);
    $guest = $setup['unit']->reservation->booker;

    $result = app(PostCharge::class)($setup['actor'], $setup['unit'], chargeData($setup['item'], ['quantity' => 2]));

    $charge = $result->line();
    $child = $result->lines[1];
    $folio = $charge->folio;

    expect($result->isRefused())->toBeFalse()
        ->and($result->lines)->toHaveCount(2)
        ->and($folio->type)->toBe(FolioType::Unit)
        ->and($folio->status)->toBe(FolioStatus::Open)
        ->and($folio->reservation_unit_id)->toBe($setup['unit']->id)
        ->and($folio->payer_guest_id)->toBe($guest->id)
        ->and($folio->number)->not->toBeEmpty()
        ->and($charge->kind)->toBe(TransactionKind::Charge)
        ->and($charge->description)->toBe($setup['item']->name)
        ->and($charge->quantity)->toBe(2)
        ->and($charge->unit_amount)->toBe('50.00')
        ->and($charge->amount)->toBe('100.00')
        ->and($charge->posting_date->toDateString())->toBe(today()->toDateString())
        ->and($charge->parent_line_id)->toBeNull()
        ->and($child->kind)->toBe(TransactionKind::Tax)
        ->and($child->description)->toBe($tax->name)
        ->and($child->amount)->toBe('8.00')
        ->and($child->parent_line_id)->toBe($charge->id)
        ->and($child->transaction_code_id)->toBe($tax->transaction_code_id)
        ->and(balanceOf($folio))->toBe('108.00')
        ->and(Activity::query()->where('event', 'folio.charge_posted')->count())->toBe(1);
});

test('the second posting reuses the unit folio', function () {
    $setup = postingSetup();

    $first = app(PostCharge::class)($setup['actor'], $setup['unit'], chargeData($setup['item']))->line();
    $second = app(PostCharge::class)($setup['actor'], $setup['unit'], chargeData($setup['item']))->line();

    expect($second->folio_id)->toBe($first->folio_id)
        ->and(Folio::query()->count())->toBe(1)
        ->and(app(FolioRouter::class)->targetFor($setup['unit'], $setup['item']->transactionCode)->id)->toBe($first->folio_id);
});

test('an inclusive tax is taken out of the entered amount', function () {
    $setup = postingSetup();
    addTax($setup['property'], $setup['item']->transactionCode, '6.00', inclusive: true);

    $result = app(PostCharge::class)($setup['actor'], $setup['unit'], chargeData($setup['item'], ['unitAmount' => '53.00']));

    expect($result->line()->amount)->toBe('50.00')
        ->and($result->lines[1]->amount)->toBe('3.00')
        ->and(balanceOf($result->line()->folio))->toBe('53.00');
});

test('an open amount posts on a charge code with the given description', function () {
    $setup = postingSetup();
    $code = TransactionCode::factory()->create(['property_id' => $setup['property']->id]);

    $line = app(PostCharge::class)($setup['actor'], $setup['unit'], chargeData($setup['item'], [
        'chargeItemId' => null, 'transactionCodeId' => $code->id, 'description' => 'Broken lamp', 'unitAmount' => '12.50',
    ]))->line();

    expect($line->description)->toBe('Broken lamp')
        ->and($line->transaction_code_id)->toBe($code->id)
        ->and($line->amount)->toBe('12.50');
});

test('a repeated key returns the existing line and writes nothing', function () {
    $setup = postingSetup();
    addTax($setup['property'], $setup['item']->transactionCode);
    $data = chargeData($setup['item'], ['idempotencyKey' => 'manual:abc']);

    $first = app(PostCharge::class)($setup['actor'], $setup['unit'], $data);
    $again = app(PostCharge::class)($setup['actor'], $setup['unit'], $data);

    expect($again->replayed)->toBeTrue()
        ->and($again->line()->id)->toBe($first->line()->id)
        ->and($again->lines)->toHaveCount(2)
        ->and(FolioLine::query()->count())->toBe(2)
        ->and(Activity::query()->where('event', 'folio.charge_posted')->count())->toBe(1);
});

test('a charge refusal writes and logs nothing', function (string $refusal, Closure $arrange) {
    $setup = postingSetup();
    $data = $arrange($setup);

    $result = app(PostCharge::class)($setup['actor'], $setup['unit']->refresh(), $data ?? chargeData($setup['item']));

    expect($result->refusal)->toBe($refusal)
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(Folio::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'no business date' => [PostingResult::REFUSAL_NO_BUSINESS_DATE, function (array $setup) {
        BusinessDate::query()->delete();
    }],
    'unit not checked in' => [PostingResult::REFUSAL_UNIT_NOT_POSTABLE, function (array $setup) {
        $setup['unit']->forceFill(['status' => 'confirmed'])->save();
    }],
    'unit of another property' => [PostingResult::REFUSAL_UNIT_NOT_POSTABLE, function (array $setup) {
        $setup['actor']->forceFill(['property_id' => Property::factory()->create()->id])->save();
    }],
    'inactive charge item' => [PostingResult::REFUSAL_CHARGE_NOT_FOUND, function (array $setup) {
        $setup['item']->forceFill(['active' => false])->save();
    }],
    'charge item of another property' => [PostingResult::REFUSAL_CHARGE_NOT_FOUND, fn (array $setup) => chargeData(ChargeItem::factory()->create())],
    'quantity 0' => [PostingResult::REFUSAL_QUANTITY_INVALID, fn (array $setup) => chargeData($setup['item'], ['quantity' => 0])],
    'quantity 1000' => [PostingResult::REFUSAL_QUANTITY_INVALID, fn (array $setup) => chargeData($setup['item'], ['quantity' => 1000])],
    'zero amount' => [PostingResult::REFUSAL_AMOUNT_INVALID, fn (array $setup) => chargeData($setup['item'], ['unitAmount' => '0.00'])],
    'three decimals' => [PostingResult::REFUSAL_AMOUNT_INVALID, fn (array $setup) => chargeData($setup['item'], ['unitAmount' => '1.005'])],
    'override not allowed' => [PostingResult::REFUSAL_AMOUNT_OVERRIDE_NOT_ALLOWED, function (array $setup) {
        $setup['item']->forceFill(['allow_override' => false])->save();

        return chargeData($setup['item'], ['unitAmount' => '49.00']);
    }],
]);

test('a closed folio refuses a posting', function () {
    $setup = postingSetup();
    Folio::factory()->forUnit($setup['unit'])->closed()->create(['property_id' => $setup['property']->id, 'reservation_id' => $setup['unit']->reservation_id]);

    $result = app(PostCharge::class)($setup['actor'], $setup['unit'], chargeData($setup['item']));

    expect($result->refusal)->toBe(PostingResult::REFUSAL_FOLIO_NOT_OPEN)
        ->and(FolioLine::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
});

test('a posting for an add-on uses the add-on key, names the add-on and marks it posted', function () {
    $setup = postingSetup();
    $addOn = UnitAddOn::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'charge_item_id' => $setup['item']->id, 'unit_amount' => '50.00']);
    $data = chargeData($setup['item'], ['unitAddOnId' => $addOn->id, 'idempotencyKey' => null]);

    $charge = app(PostCharge::class)($setup['actor'], $setup['unit'], $data)->line();
    $again = app(PostCharge::class)($setup['actor'], $setup['unit'], $data);

    expect($charge->idempotency_key)->toBe('addon:'.$addOn->id)
        ->and($charge->source_type)->toBe($addOn->getMorphClass())
        ->and($charge->source_id)->toBe($addOn->id)
        ->and($addOn->refresh()->folio_line_id)->toBe($charge->id)
        ->and($again->replayed)->toBeTrue()
        ->and(FolioLine::query()->count())->toBe(1);
});

test('CreateFolio makes a unit folio paid by the booker or a master folio, each with its own number', function () {
    $setup = postingSetup();
    $reservation = $setup['unit']->reservation;

    $unitFolio = app(CreateFolio::class)($reservation, $setup['unit']);
    $master = app(CreateFolio::class)($reservation, null);

    expect($unitFolio->type)->toBe(FolioType::Unit)
        ->and($unitFolio->payer_guest_id)->toBe($reservation->booker_guest_id)
        ->and($master->type)->toBe(FolioType::Master)
        ->and($master->reservation_unit_id)->toBeNull()
        ->and($master->number)->not->toBe($unitFolio->number);
});

test('a reversal brings the balance back to 0 and reverses the children', function () {
    $setup = postingSetup();
    $charge = postTaxedCharge($setup);
    $folio = $charge->folio;

    $result = app(ReverseLine::class)($setup['actor'], $charge);

    [$reversal, $childReversal] = $result->lines;

    expect($result->isRefused())->toBeFalse()
        ->and($reversal->reversal_of_id)->toBe($charge->id)
        ->and($reversal->amount)->toBe('-100.00')
        ->and($reversal->unit_amount)->toBe('-50.00')
        ->and($reversal->idempotency_key)->toBe('reverse:'.$charge->id)
        ->and($childReversal->reversal_of_id)->toBe($charge->children()->sole()->id)
        ->and($childReversal->parent_line_id)->toBe($reversal->id)
        ->and($childReversal->amount)->toBe('-8.00')
        ->and(balanceOf($folio))->toBe('0.00')
        ->and(Activity::query()->where('event', 'folio.line_reversed')->count())->toBe(1);
});

test('a reversal refusal writes and logs nothing', function (string $refusal, Closure $arrange) {
    $setup = postingSetup();
    $charge = postTaxedCharge($setup);
    $line = $arrange($charge, $setup);
    $count = FolioLine::query()->count();
    $logged = Activity::query()->count();

    $result = app(ReverseLine::class)($setup['actor'], $line);

    expect($result->refusal)->toBe($refusal)
        ->and(FolioLine::query()->count())->toBe($count)
        ->and(Activity::query()->count())->toBe($logged);
})->with([
    'second reversal' => [PostingResult::REFUSAL_ALREADY_REVERSED, function (FolioLine $charge, array $setup) {
        app(ReverseLine::class)($setup['actor'], $charge);

        return $charge;
    }],
    'a child line' => [PostingResult::REFUSAL_LINE_IS_CHILD, fn (FolioLine $charge) => $charge->children()->sole()],
    'a reversal line' => [PostingResult::REFUSAL_LINE_IS_REVERSAL, function (FolioLine $charge, array $setup) {
        return app(ReverseLine::class)($setup['actor'], $charge)->line();
    }],
    'a reversed child line' => [PostingResult::REFUSAL_LINE_IS_CHILD, function (FolioLine $charge, array $setup) {
        return app(ReverseLine::class)($setup['actor'], $charge)->lines[1];
    }],
    'no business date' => [PostingResult::REFUSAL_NO_BUSINESS_DATE, function (FolioLine $charge) {
        BusinessDate::query()->delete();

        return $charge;
    }],
    'closed folio' => [PostingResult::REFUSAL_FOLIO_NOT_OPEN, function (FolioLine $charge) {
        $charge->folio->forceFill(['status' => FolioStatus::Closed, 'closed_at' => now(), 'closed_by' => User::factory()->create()->id])->save();

        return $charge;
    }],
]);

test('reversing a posted add-on keeps its folio line', function () {
    $setup = postingSetup();
    $addOn = UnitAddOn::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'charge_item_id' => $setup['item']->id]);
    $charge = app(PostCharge::class)($setup['actor'], $setup['unit'], chargeData($setup['item'], ['unitAddOnId' => $addOn->id]))->line();

    app(ReverseLine::class)($setup['actor'], $charge);

    expect($addOn->refresh()->folio_line_id)->toBe($charge->id);
});

test('a transfer moves a charge and its children, and the balances change by the same amount', function () {
    $setup = postingSetup();
    $charge = postTaxedCharge($setup);
    $source = $charge->folio;
    $target = Folio::factory()->create(['property_id' => $setup['property']->id, 'reservation_id' => $source->reservation_id]);

    $result = app(TransferLines::class)($setup['actor'], [$charge], $target);

    [$copy, $childCopy] = $result->lines;
    $group = $copy->transfer_group_id;

    expect($result->isRefused())->toBeFalse()
        ->and($group)->not->toBeNull()
        ->and($childCopy->transfer_group_id)->toBe($group)
        ->and($copy->idempotency_key)->toBe('transfer:'.$group.':'.$charge->id)
        ->and($copy->folio_id)->toBe($target->id)
        ->and($copy->description)->toBe($charge->description)
        ->and($copy->kind)->toBe($charge->kind)
        ->and($copy->quantity)->toBe(2)
        ->and($copy->unit_amount)->toBe('50.00')
        ->and($childCopy->parent_line_id)->toBe($copy->id)
        ->and(FolioLine::query()->where('reversal_of_id', $charge->id)->sole()->transfer_group_id)->toBe($group)
        ->and(balanceOf($source))->toBe('0.00')
        ->and(balanceOf($target))->toBe('108.00')
        ->and(FolioLine::query()->whereNotNull('transfer_group_id')->pluck('transfer_group_id')->unique()->all())->toBe([$group]);
});

test('a transfer refusal writes and logs nothing', function (string $refusal, Closure $arrange) {
    $setup = postingSetup();
    $charge = postTaxedCharge($setup);
    $target = Folio::factory()->create(['property_id' => $setup['property']->id, 'reservation_id' => $charge->folio->reservation_id]);
    [$lines, $to] = $arrange($charge, $target, $setup);
    $count = FolioLine::query()->count();
    $logged = Activity::query()->count();

    $result = app(TransferLines::class)($setup['actor'], $lines, $to);

    expect($result->refusal)->toBe($refusal)
        ->and(FolioLine::query()->count())->toBe($count)
        ->and(Activity::query()->count())->toBe($logged);
})->with([
    'the target is the source folio' => [PostingResult::REFUSAL_TRANSFER_INVALID, fn (FolioLine $charge, Folio $target) => [[$charge], $charge->folio]],
    'no lines' => [PostingResult::REFUSAL_TRANSFER_INVALID, fn (FolioLine $charge, Folio $target) => [[], $target]],
    'lines of two folios' => [PostingResult::REFUSAL_TRANSFER_INVALID, fn (FolioLine $charge, Folio $target) => [[$charge, FolioLine::factory()->for($target)->create()], $target]],
    'another reservation' => [PostingResult::REFUSAL_DIFFERENT_RESERVATION, fn (FolioLine $charge, Folio $target, array $setup) => [[$charge], Folio::factory()->create(['property_id' => $setup['property']->id])]],
    'a closed target' => [PostingResult::REFUSAL_FOLIO_NOT_OPEN, fn (FolioLine $charge) => [[$charge], Folio::factory()->thirdParty()->closed()->create(['property_id' => $charge->folio->property_id, 'reservation_id' => $charge->folio->reservation_id])]],
    'a child line' => [PostingResult::REFUSAL_LINE_NOT_TRANSFERABLE, fn (FolioLine $charge, Folio $target) => [[$charge->children()->sole()], $target]],
    'an already reversed line' => [PostingResult::REFUSAL_LINE_NOT_TRANSFERABLE, function (FolioLine $charge, Folio $target, array $setup) {
        app(ReverseLine::class)($setup['actor'], $charge);

        return [[$charge], $target];
    }],
    'no business date' => [PostingResult::REFUSAL_NO_BUSINESS_DATE, function (FolioLine $charge, Folio $target) {
        BusinessDate::query()->delete();

        return [[$charge], $target];
    }],
]);

test('posted lines stay immutable in the database', function () {
    $setup = postingSetup();
    $charge = app(PostCharge::class)($setup['actor'], $setup['unit'], chargeData($setup['item']))->line();

    expect(fn () => DB::transaction(fn () => DB::table('folio_lines')->where('id', $charge->id)->update(['amount' => '1.00'])))
        ->toThrow(QueryException::class, 'immutable ledger')
        ->and(fn () => DB::transaction(fn () => DB::table('folio_lines')->where('id', $charge->id)->delete()))
        ->toThrow(QueryException::class, 'immutable ledger');
});
