<?php

use App\Actions\Folio\ApplyDiscount;
use App\Actions\Folio\PostCharge;
use App\Actions\Folio\PostingResult;
use App\Actions\Folio\RemoveDiscount;
use App\Actions\Folio\ReverseLine;
use App\Actions\Folio\TransferLines;
use App\Data\Folio\DiscountQuoteData;
use App\Data\Folio\NewChargeData;
use App\Enums\DiscountScope;
use App\Enums\FolioStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Enums\TransactionKind;
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
use App\Services\Folio\DiscountQuote;
use App\Services\Folio\FolioBalance;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/**
 * A live property with an open business date (today), a CheckedIn unit, an actor and the room revenue code.
 *
 * @return array{property: Property, actor: User, unit: ReservationUnit, room: TransactionCode}
 */
function fdSetup(): array
{
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);
    $unit = ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $reservation->id]);

    return [
        'property' => $property,
        'actor' => User::factory()->create(['property_id' => $property->id]),
        'unit' => $unit,
        'room' => TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]),
    ];
}

/**
 * @param  array{property: Property, actor: User, unit: ReservationUnit, room: TransactionCode}  $setup
 */
function fdCharge(array $setup, ?TransactionCode $code = null, string $amount = '100.00'): FolioLine
{
    $result = app(PostCharge::class)($setup['actor'], $setup['unit'], NewChargeData::from([
        'chargeItemId' => null,
        'transactionCodeId' => ($code ?? $setup['room'])->id,
        'description' => null,
        'quantity' => 1,
        'unitAmount' => $amount,
        'idempotencyKey' => 'manual:'.fake()->uuid(),
    ]));

    return $result->line();
}

function fdTax(Property $property, TransactionCode $code, string $rate = '8.00', TaxRateType $type = TaxRateType::Percent, bool $inclusive = false): Tax
{
    $tax = Tax::factory()->for($property)->create(['rate' => $rate, 'rate_type' => $type, 'is_inclusive' => $inclusive]);
    $tax->applicableTransactionCodes()->attach($code);

    return $tax;
}

function fdQuote(Folio $folio, Discount $discount, ?string $amount = null): DiscountQuoteData
{
    return app(DiscountQuote::class)->quote($folio->refresh(), $discount->refresh(), CarbonImmutable::parse(today()->toDateString()), $amount);
}

/**
 * Quotes and applies in one go, as the screen will.
 *
 * @param  array{property: Property, actor: User, unit: ReservationUnit, room: TransactionCode}  $setup
 */
function fdApply(array $setup, Folio $folio, Discount $discount, ?string $amount = null): PostingResult
{
    return app(ApplyDiscount::class)($setup['actor'], $folio, $discount, $amount, fdQuote($folio, $discount, $amount)->fingerprint);
}

function fdBalance(Folio $folio): string
{
    return app(FolioBalance::class)->forFolio($folio);
}

/** @param  array<string, mixed>  $attributes */
function fdDiscount(Property $property, array $attributes = [], string $state = 'percent'): Discount
{
    $factory = Discount::factory()->for($property);

    return match ($state) {
        'fixed' => $factory->fixed()->create($attributes),
        'open' => $factory->open()->create($attributes),
        default => $factory->create($attributes),
    };
}

test('the quote picks charges by scope and skips what is not eligible', function () {
    $setup = fdSetup();
    $other = TransactionCode::factory()->create(['property_id' => $setup['property']->id]);
    $selected = TransactionCode::factory()->create(['property_id' => $setup['property']->id]);
    fdTax($setup['property'], $setup['room']);
    $room = fdCharge($setup);
    $extra = fdCharge($setup, $other, '40.00');
    $pick = fdCharge($setup, $selected, '30.00');
    $folio = $room->folio;
    app(ReverseLine::class)($setup['actor'], fdCharge($setup, $other, '5.00'));
    FolioLine::factory()->create(['folio_id' => $folio->id, 'transaction_code_id' => $other->id, 'amount' => '9.00', 'unit_amount' => '9.00', 'posting_date' => today()->addDay()->toDateString()]);

    $roomOnly = fdDiscount($setup['property'], ['scope' => DiscountScope::RoomOnly]);
    $all = fdDiscount($setup['property'], ['scope' => DiscountScope::AllCharges]);
    $some = fdDiscount($setup['property'], ['scope' => DiscountScope::Selected]);
    $some->selectedTransactionCodes()->attach($selected);

    expect(array_column(fdQuote($folio, $roomOnly)->lines, 'folioLineId'))->toBe([$room->id])
        ->and(array_column(fdQuote($folio, $all)->lines, 'folioLineId'))->toBe([$room->id, $extra->id, $pick->id])
        ->and(array_column(fdQuote($folio, $some)->lines, 'folioLineId'))->toBe([$pick->id])
        ->and(fdQuote($folio, $all)->subtotal)->toBe('170.00');

    $folio->forceFill(['status' => FolioStatus::Closed])->save();

    expect(fdQuote($folio, $all)->lines)->toBe([]);
});

test('percent, fixed and open discounts work out the amount', function () {
    $setup = fdSetup();
    $first = fdCharge($setup, null, '100.00');
    fdCharge($setup, null, '50.00');
    $folio = $first->folio;
    $percent = fdDiscount($setup['property'], ['value' => '10.00']);
    $fixed = fdDiscount($setup['property'], ['value' => '30.00', 'allow_override' => true], 'fixed');
    $open = fdDiscount($setup['property'], [], 'open');

    $quote = fdQuote($folio, $percent, '1.00');

    expect($quote->amount)->toBe('15.00')
        ->and(array_column($quote->lines, 'share'))->toBe(['10.00', '5.00'])
        ->and($quote->amountRefusal)->toBeNull()
        ->and(fdQuote($folio, $fixed)->amount)->toBe('30.00')
        ->and(fdQuote($folio, $fixed, '40')->amount)->toBe('40.00')
        ->and(fdQuote($folio, $open, '12.50')->amount)->toBe('12.50')
        ->and(fdQuote($folio, $open)->amountRefusal)->toBe(PostingResult::REFUSAL_AMOUNT_REQUIRED)
        ->and(fdQuote($folio, $open)->amount)->toBeNull();
});

test('a fixed or open amount splits in proportion and the last charge takes the remainder', function () {
    $setup = fdSetup();
    $lines = [fdCharge($setup), fdCharge($setup), fdCharge($setup)];
    $open = fdDiscount($setup['property'], ['scope' => DiscountScope::AllCharges], 'open');

    $quote = fdQuote($lines[0]->folio, $open, '100.00');

    expect(array_column($quote->lines, 'share'))->toBe(['33.33', '33.33', '33.34']);

    $result = fdApply($setup, $lines[0]->folio, $open, '100.00');

    expect(array_map(fn (FolioLine $line): string => $line->amount, $result->lines))->toBe(['-33.33', '-33.33', '-33.34'])
        ->and(fdBalance($lines[0]->folio))->toBe('200.00');
});

test('applying posts a discount line under the charge and reduces only percent taxes', function () {
    $setup = fdSetup();
    $sst = fdTax($setup['property'], $setup['room'], '8.00');
    $tourism = fdTax($setup['property'], $setup['room'], '10.00', TaxRateType::Flat);
    $charge = fdCharge($setup);
    $folio = $charge->folio;
    $discount = fdDiscount($setup['property'], ['value' => '10.00']);

    expect(fdBalance($folio))->toBe('118.00');

    $result = fdApply($setup, $folio, $discount);

    [$line, $taxLine] = $result->lines;

    expect($result->isRefused())->toBeFalse()
        ->and($result->lines)->toHaveCount(2)
        ->and($line->kind)->toBe(TransactionKind::Adjustment)
        ->and($line->transaction_code_id)->toBe($discount->transaction_code_id)
        ->and($line->quantity)->toBe(1)
        ->and($line->unit_amount)->toBe('-10.00')
        ->and($line->amount)->toBe('-10.00')
        ->and($line->parent_line_id)->toBe($charge->id)
        ->and($line->idempotency_key)->toBe('discount:'.$discount->id.':'.$charge->id)
        ->and($line->posting_date->toDateString())->toBe(today()->toDateString())
        ->and($taxLine->kind)->toBe(TransactionKind::Tax)
        ->and($taxLine->amount)->toBe('-0.80')
        ->and($taxLine->transaction_code_id)->toBe($sst->transaction_code_id)
        ->and($taxLine->parent_line_id)->toBe($line->id)
        ->and($taxLine->idempotency_key)->toBe($line->idempotency_key.':tax:'.$sst->id)
        ->and($taxLine->transaction_code_id)->not->toBe($tourism->transaction_code_id)
        ->and(fdBalance($folio))->toBe('107.20')
        ->and(Activity::query()->where('event', 'folio.discount_applied')->count())->toBe(1);
});

test('a discount on an inclusive tax charge lowers the tax in proportion', function () {
    $setup = fdSetup();
    fdTax($setup['property'], $setup['room'], '6.00', inclusive: true);
    $charge = fdCharge($setup, null, '106.00');
    $discount = fdDiscount($setup['property'], ['value' => '50.00']);

    expect($charge->amount)->toBe('100.00')
        ->and(fdBalance($charge->folio))->toBe('106.00');

    $result = fdApply($setup, $charge->folio, $discount);

    expect(array_map(fn (FolioLine $line): string => $line->amount, $result->lines))->toBe(['-50.00', '-3.00'])
        ->and(fdBalance($charge->folio))->toBe('53.00');
});

test('a tax whose row was deleted counts as flat and gets no line', function () {
    $setup = fdSetup();
    $tax = fdTax($setup['property'], $setup['room'], '8.00');
    $charge = fdCharge($setup);
    $tax->applicableTransactionCodes()->detach();
    $tax->delete();

    $result = fdApply($setup, $charge->folio, fdDiscount($setup['property']));

    expect($result->lines)->toHaveCount(1)
        ->and(fdBalance($charge->folio))->toBe('98.00');
});

test('a stale fingerprint is refused and writes nothing', function () {
    $setup = fdSetup();
    $charge = fdCharge($setup);
    $folio = $charge->folio;
    $discount = fdDiscount($setup['property'], ['value' => '10.00']);
    $fingerprint = fdQuote($folio, $discount)->fingerprint;
    $count = FolioLine::query()->count();

    fdCharge($setup);
    $afterCharge = app(ApplyDiscount::class)($setup['actor'], $folio, $discount, null, $fingerprint);
    $fresh = fdQuote($folio, $discount)->fingerprint;

    $this->travel(1)->minute();
    $discount->forceFill(['value' => '20.00'])->save();
    $afterChange = app(ApplyDiscount::class)($setup['actor'], $folio, $discount, null, $fresh);

    expect($afterCharge->refusal)->toBe(PostingResult::REFUSAL_QUOTE_CHANGED)
        ->and($afterChange->refusal)->toBe(PostingResult::REFUSAL_QUOTE_CHANGED)
        ->and(FolioLine::query()->count())->toBe($count + 1)
        ->and(Activity::query()->where('event', 'folio.discount_applied')->count())->toBe(0);
});

test('the fingerprint is a SHA-256 hash that only changes with the quote', function () {
    $setup = fdSetup();
    $charge = fdCharge($setup);
    $discount = fdDiscount($setup['property']);

    $first = fdQuote($charge->folio, $discount)->fingerprint;

    expect($first)->toMatch('/^[0-9a-f]{64}$/')
        ->and(fdQuote($charge->folio, $discount)->fingerprint)->toBe($first)
        ->and(fdQuote($charge->folio, $discount, '5.00')->fingerprint)->toBe($first);
});

test('a charge takes one discount: a second apply posts nothing', function () {
    $setup = fdSetup();
    $charge = fdCharge($setup);
    $folio = $charge->folio;
    $discount = fdDiscount($setup['property']);
    $stale = fdQuote($folio, $discount)->fingerprint;

    fdApply($setup, $folio, $discount);
    $count = FolioLine::query()->count();
    $again = app(ApplyDiscount::class)($setup['actor'], $folio, $discount, null, $stale);
    $other = fdApply($setup, $folio, fdDiscount($setup['property'], ['value' => '5.00']));

    expect($again->refusal)->toBe(PostingResult::REFUSAL_NOTHING_ELIGIBLE)
        ->and($other->refusal)->toBe(PostingResult::REFUSAL_NOTHING_ELIGIBLE)
        ->and(FolioLine::query()->count())->toBe($count)
        ->and(Activity::query()->where('event', 'folio.discount_applied')->count())->toBe(1);
});

test('a discount line that is already there, such as a night audit discount, makes the charge ineligible', function () {
    $setup = fdSetup();
    $charge = fdCharge($setup);
    $standing = fdDiscount($setup['property']);
    FolioLine::factory()->create([
        'folio_id' => $charge->folio_id, 'transaction_code_id' => $standing->transaction_code_id, 'kind' => TransactionKind::Adjustment,
        'amount' => '-20.00', 'unit_amount' => '-20.00', 'parent_line_id' => $charge->id, 'idempotency_key' => 'night:77:discount',
    ]);

    $result = fdApply($setup, $charge->folio, fdDiscount($setup['property']));

    expect($result->refusal)->toBe(PostingResult::REFUSAL_NOTHING_ELIGIBLE);
});

test('an amount refusal writes and logs nothing', function (string $refusal, string $state, array $attributes, ?string $amount) {
    $setup = fdSetup();
    $charge = fdCharge($setup);
    $discount = fdDiscount($setup['property'], $attributes, $state);
    $count = FolioLine::query()->count();

    $result = fdApply($setup, $charge->folio, $discount, $amount);

    expect($result->refusal)->toBe($refusal)
        ->and(FolioLine::query()->count())->toBe($count)
        ->and(Activity::query()->where('event', 'folio.discount_applied')->count())->toBe(0);
})->with([
    'open without amount' => [PostingResult::REFUSAL_AMOUNT_REQUIRED, 'open', [], null],
    'fixed without a value' => [PostingResult::REFUSAL_AMOUNT_REQUIRED, 'fixed', ['value' => null], null],
    'zero' => [PostingResult::REFUSAL_AMOUNT_INVALID, 'open', [], '0.00'],
    'three decimals' => [PostingResult::REFUSAL_AMOUNT_INVALID, 'open', [], '1.005'],
    'not a number' => [PostingResult::REFUSAL_AMOUNT_INVALID, 'open', [], 'ten'],
    'above the subtotal' => [PostingResult::REFUSAL_AMOUNT_INVALID, 'open', [], '100.01'],
    'fixed value above the subtotal' => [PostingResult::REFUSAL_AMOUNT_INVALID, 'fixed', ['value' => '150.00'], null],
    'fixed changed without override' => [PostingResult::REFUSAL_AMOUNT_OVERRIDE_NOT_ALLOWED, 'fixed', ['value' => '30.00', 'allow_override' => false], '20.00'],
]);

test('a fixed amount without a change applies when override is off', function () {
    $setup = fdSetup();
    $charge = fdCharge($setup);
    $discount = fdDiscount($setup['property'], ['value' => '30.00', 'allow_override' => false], 'fixed');

    $result = fdApply($setup, $charge->folio, $discount, '30.00');

    expect($result->isRefused())->toBeFalse()
        ->and($result->line()->amount)->toBe('-30.00');
});

test('the checks run in the order of the spec and refuse without a write', function (string $refusal, Closure $arrange) {
    $setup = fdSetup();
    $charge = fdCharge($setup);
    $discount = fdDiscount($setup['property']);
    $arrange($setup, $charge, $discount);
    $count = FolioLine::query()->count();

    $result = app(ApplyDiscount::class)($setup['actor'], $charge->folio, $discount, null, 'x');

    expect($result->refusal)->toBe($refusal)
        ->and(FolioLine::query()->count())->toBe($count)
        ->and(Activity::query()->where('event', 'folio.discount_applied')->count())->toBe(0);
})->with([
    'no business date' => [PostingResult::REFUSAL_NO_BUSINESS_DATE, fn () => BusinessDate::query()->delete()],
    'discount of another property' => [PostingResult::REFUSAL_DISCOUNT_NOT_FOUND, fn ($setup, $charge, $discount) => $discount->forceFill(['property_id' => Property::factory()->create()->id])->save()],
    'inactive discount' => [PostingResult::REFUSAL_DISCOUNT_NOT_FOUND, fn ($setup, $charge, $discount) => $discount->forceFill(['active' => false])->save()],
    'closed folio' => [PostingResult::REFUSAL_FOLIO_NOT_OPEN, fn ($setup, $charge) => $charge->folio->forceFill(['status' => FolioStatus::Closed])->save()],
    'nothing eligible' => [PostingResult::REFUSAL_NOTHING_ELIGIBLE, fn ($setup, $charge, $discount) => $discount->forceFill(['scope' => DiscountScope::Selected])->save()],
    'changed quote' => [PostingResult::REFUSAL_QUOTE_CHANGED, fn () => null],
]);

test('removing a discount reverses it and its tax and leaves the charge', function () {
    $setup = fdSetup();
    fdTax($setup['property'], $setup['room'], '8.00');
    $charge = fdCharge($setup);
    $folio = $charge->folio;
    $result = fdApply($setup, $folio, fdDiscount($setup['property']));

    $removed = app(RemoveDiscount::class)($setup['actor'], $result->line());

    expect($removed->isRefused())->toBeFalse()
        ->and($removed->lines)->toHaveCount(2)
        ->and($removed->lines[0]->reversal_of_id)->toBe($result->line()->id)
        ->and($removed->lines[0]->amount)->toBe('10.00')
        ->and($removed->lines[1]->reversal_of_id)->toBe($result->lines[1]->id)
        ->and($removed->lines[1]->parent_line_id)->toBe($removed->lines[0]->id)
        ->and($removed->lines[1]->amount)->toBe('0.80')
        ->and(fdBalance($folio))->toBe('108.00')
        ->and($charge->refresh()->reversal)->toBeNull()
        ->and(Activity::query()->where('event', 'folio.discount_removed')->count())->toBe(1);
});

test('a removed discount cannot go back on the same charge, but another discount can', function () {
    $setup = fdSetup();
    $charge = fdCharge($setup);
    $folio = $charge->folio;
    $discount = fdDiscount($setup['property']);
    app(RemoveDiscount::class)($setup['actor'], fdApply($setup, $folio, $discount)->line());

    $same = fdApply($setup, $folio, $discount);
    $another = fdApply($setup, $folio, fdDiscount($setup['property'], ['value' => '20.00']));

    expect($same->refusal)->toBe(PostingResult::REFUSAL_NOTHING_ELIGIBLE)
        ->and($another->isRefused())->toBeFalse()
        ->and(fdBalance($folio))->toBe('80.00');
});

test('removing refuses a line that is not a discount, is reversed already, or sits on a closed folio', function () {
    $setup = fdSetup();
    fdTax($setup['property'], $setup['room'], '8.00');
    $charge = fdCharge($setup);
    $folio = $charge->folio;
    $result = fdApply($setup, $folio, fdDiscount($setup['property']));
    $discountLine = $result->line();
    $count = FolioLine::query()->count();
    $remove = fn (FolioLine $line): ?string => app(RemoveDiscount::class)($setup['actor'], $line)->refusal;

    expect($remove($charge))->toBe(PostingResult::REFUSAL_NOT_A_DISCOUNT)
        ->and($remove($result->lines[1]))->toBe(PostingResult::REFUSAL_NOT_A_DISCOUNT)
        ->and($remove($charge->children()->where('kind', TransactionKind::Tax)->first()))->toBe(PostingResult::REFUSAL_NOT_A_DISCOUNT);

    $folio->forceFill(['status' => FolioStatus::Closed])->save();

    expect($remove($discountLine))->toBe(PostingResult::REFUSAL_FOLIO_NOT_OPEN);

    $folio->forceFill(['status' => FolioStatus::Open])->save();
    BusinessDate::query()->delete();
    $withoutDate = $remove($discountLine);
    BusinessDate::factory()->create(['property_id' => $setup['property']->id]);
    app(RemoveDiscount::class)($setup['actor'], $discountLine);

    expect($withoutDate)->toBe(PostingResult::REFUSAL_NO_BUSINESS_DATE)
        ->and($remove($discountLine))->toBe(PostingResult::REFUSAL_ALREADY_REVERSED)
        ->and($remove(FolioLine::query()->where('reversal_of_id', $discountLine->id)->firstOrFail()))->toBe(PostingResult::REFUSAL_NOT_A_DISCOUNT)
        ->and(FolioLine::query()->count())->toBe($count + 2);
});

test('reversing a discounted charge reverses the discount and its tax lines at every level', function () {
    $setup = fdSetup();
    fdTax($setup['property'], $setup['room'], '8.00');
    $charge = fdCharge($setup);
    $folio = $charge->folio;
    $applied = fdApply($setup, $folio, fdDiscount($setup['property']));

    $result = app(ReverseLine::class)($setup['actor'], $charge);

    expect($result->isRefused())->toBeFalse()
        ->and($result->lines)->toHaveCount(4)
        ->and(fdBalance($folio))->toBe('0.00')
        ->and($result->lines[0]->reversal_of_id)->toBe($charge->id)
        ->and(collect($result->lines)->pluck('reversal_of_id')->sort()->values()->all())->toBe(collect([$charge->id, $charge->children()->where('kind', TransactionKind::Tax)->value('id'), ...array_column($applied->lines, 'id')])->sort()->values()->all());

    $reversalOfDiscount = FolioLine::query()->where('reversal_of_id', $applied->line()->id)->firstOrFail();
    $reversalOfDiscountTax = FolioLine::query()->where('reversal_of_id', $applied->lines[1]->id)->firstOrFail();

    expect($reversalOfDiscount->parent_line_id)->toBe($result->lines[0]->id)
        ->and($reversalOfDiscountTax->parent_line_id)->toBe($reversalOfDiscount->id);
});

test('reversing a charge after its discount was removed does not break or double reverse', function () {
    $setup = fdSetup();
    fdTax($setup['property'], $setup['room'], '8.00');
    $charge = fdCharge($setup);
    $folio = $charge->folio;
    $applied = fdApply($setup, $folio, fdDiscount($setup['property']));
    app(RemoveDiscount::class)($setup['actor'], $applied->line());
    $count = FolioLine::query()->count();

    $result = app(ReverseLine::class)($setup['actor'], $charge);

    expect($result->isRefused())->toBeFalse()
        ->and($result->lines)->toHaveCount(2)
        ->and(FolioLine::query()->count())->toBe($count + 2)
        ->and(fdBalance($folio))->toBe('0.00')
        ->and(FolioLine::query()->where('reversal_of_id', $applied->line()->id)->count())->toBe(1);
});

test('transferring a discounted charge moves it with every nested child', function () {
    $setup = fdSetup();
    fdTax($setup['property'], $setup['room'], '8.00');
    $charge = fdCharge($setup);
    $source = $charge->folio;
    $target = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $source->reservation_id]);
    fdApply($setup, $source, fdDiscount($setup['property']));
    $before = fdBalance($source);

    $result = app(TransferLines::class)($setup['actor'], [$charge], $target);

    $copies = $result->lines;

    expect($result->isRefused())->toBeFalse()
        ->and($copies)->toHaveCount(4)
        ->and(fdBalance($source))->toBe('0.00')
        ->and(fdBalance($target))->toBe($before)
        ->and($copies[1]->parent_line_id)->toBe($copies[0]->id)
        ->and($copies[2]->kind)->toBe(TransactionKind::Adjustment)
        ->and($copies[2]->parent_line_id)->toBe($copies[0]->id)
        ->and($copies[3]->parent_line_id)->toBe($copies[2]->id)
        ->and(FolioLine::query()->where('folio_id', $target->id)->count())->toBe(4);
});

test('transferring a charge whose discount was removed copies no discount and does not break', function () {
    $setup = fdSetup();
    fdTax($setup['property'], $setup['room'], '8.00');
    $charge = fdCharge($setup);
    $source = $charge->folio;
    $target = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => $source->reservation_id]);
    app(RemoveDiscount::class)($setup['actor'], fdApply($setup, $source, fdDiscount($setup['property']))->line());

    $result = app(TransferLines::class)($setup['actor'], [$charge], $target);

    expect($result->isRefused())->toBeFalse()
        ->and($result->lines)->toHaveCount(2)
        ->and(fdBalance($source))->toBe('0.00')
        ->and(fdBalance($target))->toBe('108.00');
});

test('a discount of another property is never found for this folio', function () {
    $setup = fdSetup();
    $charge = fdCharge($setup);
    $foreign = Discount::factory()->create();

    $result = app(ApplyDiscount::class)($setup['actor'], $charge->folio, $foreign, null, 'x');

    expect($result->refusal)->toBe(PostingResult::REFUSAL_DISCOUNT_NOT_FOUND);
});
