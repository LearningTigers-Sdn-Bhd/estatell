<?php

use App\Actions\Pos\MealRedemptionResult;
use App\Actions\Pos\RedeemMeal;
use App\Actions\Pos\UndoMealRedemption;
use App\Enums\UnitStatus;
use App\Models\MealPeriod;
use App\Models\MealRedemption;
use App\Services\Pos\DailyMealCheckInList;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/PosSetup.php';

test('the list shows entitled, redeemed and remaining guests per room', function () {
    $setup = mealSetup();
    $redeem = app(RedeemMeal::class);
    $redeem($setup['actor'], $setup['unit'], $setup['period'], $setup['date'], 2);

    $list = app(DailyMealCheckInList::class)->forPeriod($setup['property'], $setup['date'], $setup['period']);
    $row = $list->rows[0];

    expect($list->totalEntitled)->toBe(3)
        ->and($list->checkedIn)->toBe(2)
        ->and($list->remaining)->toBe(1)
        ->and($row->reservationUnitId)->toBe($setup['unit']->id)
        ->and($row->status)->toBe('partial')
        ->and($row->remainingGuests)->toBe(1)
        ->and(app(DailyMealCheckInList::class)->forPeriod($setup['property'], $setup['date'], $setup['period'], 'zzz-no-match')->rows)->toBe([]);
});

test('redemptions add up to the entitled guests and never past them', function () {
    $setup = mealSetup();
    $redeem = app(RedeemMeal::class);

    $first = $redeem($setup['actor'], $setup['unit'], $setup['period'], $setup['date'], 2);
    $over = $redeem($setup['actor'], $setup['unit'], $setup['period'], $setup['date'], 2);
    $last = $redeem($setup['actor'], $setup['unit'], $setup['period'], $setup['date'], 1);
    $none = $redeem($setup['actor'], $setup['unit'], $setup['period'], $setup['date'], 1);

    expect($first->isRefused())->toBeFalse()
        ->and($over->refusal)->toBe(MealRedemptionResult::REFUSAL_EXCEEDS_ENTITLEMENT)
        ->and($last->isRefused())->toBeFalse()
        ->and($none->refusal)->toBe(MealRedemptionResult::REFUSAL_EXCEEDS_ENTITLEMENT)
        ->and((int) MealRedemption::query()->active()->sum('redeemed_guests'))->toBe(3)
        ->and(Activity::query()->where('event', 'meals.redeemed')->count())->toBe(2)
        ->and(app(DailyMealCheckInList::class)->forPeriod($setup['property'], $setup['date'], $setup['period'])->rows[0]->status)->toBe('redeemed');
});

test('a redemption refuses an ineligible unit, a bad count, a missing entitlement and another property period', function () {
    $setup = mealSetup();
    $redeem = app(RedeemMeal::class);
    $other = MealPeriod::factory()->create(['property_id' => $setup['property']->id]);
    $foreign = MealPeriod::factory()->create();

    $refusals = [
        $redeem($setup['actor'], $setup['unit'], $setup['period'], $setup['date'], 0)->refusal,
        $redeem($setup['actor'], $setup['unit'], $setup['period'], $setup['date'], 100)->refusal,
        $redeem($setup['actor'], $setup['unit'], $other, $setup['date'], 1)->refusal,
        $redeem($setup['actor'], $setup['unit'], $foreign, $setup['date'], 1)->refusal,
        $redeem($setup['actor'], $setup['unit'], $setup['period'], CarbonImmutable::parse('2026-10-20'), 1)->refusal,
    ];
    posUnitStatus($setup['unit'], UnitStatus::Confirmed);
    $refusals[] = $redeem($setup['actor'], $setup['unit'], $setup['period'], $setup['date'], 1)->refusal;

    expect($refusals)->toBe([
        MealRedemptionResult::REFUSAL_GUEST_COUNT_INVALID,
        MealRedemptionResult::REFUSAL_GUEST_COUNT_INVALID,
        MealRedemptionResult::REFUSAL_NOT_ENTITLED,
        MealRedemptionResult::REFUSAL_MEAL_PERIOD_NOT_FOUND,
        MealRedemptionResult::REFUSAL_NOT_ENTITLED,
        MealRedemptionResult::REFUSAL_UNIT_NOT_ELIGIBLE,
    ])->and(MealRedemption::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'meals.redeemed')->count())->toBe(0);
});

test('a checked out unit can redeem only on its departure date', function () {
    $setup = mealSetup();
    $setup['unit']->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();
    $redeem = app(RedeemMeal::class);

    $early = $redeem($setup['actor'], $setup['unit'], $setup['period'], $setup['date'], 1);
    $departure = $redeem($setup['actor'], $setup['unit'], $setup['period'], CarbonImmutable::parse('2026-10-16'), 1);

    expect($early->refusal)->toBe(MealRedemptionResult::REFUSAL_UNIT_NOT_ELIGIBLE)
        ->and($departure->refusal)->not->toBe(MealRedemptionResult::REFUSAL_UNIT_NOT_ELIGIBLE);
});

test('undoing a redemption frees its guests and keeps the row', function () {
    $setup = mealSetup();
    $redemption = app(RedeemMeal::class)($setup['actor'], $setup['unit'], $setup['period'], $setup['date'], 3)->redemption;

    $undo = app(UndoMealRedemption::class)($setup['actor'], $redemption);
    $again = app(UndoMealRedemption::class)($setup['actor'], $redemption);
    $redeemAgain = app(RedeemMeal::class)($setup['actor'], $setup['unit'], $setup['period'], $setup['date'], 3);

    expect($undo->isRefused())->toBeFalse()
        ->and($redemption->fresh()->undone_by_user_id)->toBe($setup['actor']->id)
        ->and($again->refusal)->toBe(MealRedemptionResult::REFUSAL_ALREADY_UNDONE)
        ->and($redeemAgain->isRefused())->toBeFalse()
        ->and(MealRedemption::query()->count())->toBe(2)
        ->and(Activity::query()->where('event', 'meals.redemption_undone')->count())->toBe(1);
});

test('a redemption from a closed service date cannot be undone', function () {
    $setup = mealSetup();
    $old = MealRedemption::factory()->create([
        'reservation_unit_id' => $setup['unit']->id,
        'meal_period_id' => $setup['period']->id,
        'service_date' => '2026-10-13',
    ]);

    $result = app(UndoMealRedemption::class)($setup['actor'], $old);

    expect($result->refusal)->toBe(MealRedemptionResult::REFUSAL_SERVICE_DATE_CLOSED)
        ->and($old->fresh()->undone_at)->toBeNull();
});

test('the list keeps a unit that checked out on its departure date and drops one that left earlier', function () {
    $setup = mealSetup();
    $setup['unit']->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();
    $list = app(DailyMealCheckInList::class);

    $onDeparture = $list->forPeriod($setup['property'], CarbonImmutable::parse('2026-10-16'), $setup['period']);
    $before = $list->forPeriod($setup['property'], $setup['date'], $setup['period']);

    expect($onDeparture->rows)->toHaveCount(1)
        ->and($onDeparture->rows[0]->reservationUnitId)->toBe($setup['unit']->id)
        ->and($onDeparture->totalEntitled)->toBe(3)
        ->and($before->rows)->toBe([]);
});

test('a departure day guest can be redeemed from the list it appears in', function () {
    $setup = mealSetup();
    $setup['unit']->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();
    $departure = CarbonImmutable::parse('2026-10-16');

    app(RedeemMeal::class)($setup['actor'], $setup['unit'], $setup['period'], $departure, 2);
    $row = app(DailyMealCheckInList::class)->forPeriod($setup['property'], $departure, $setup['period'])->rows[0];

    expect($row->redeemedGuests)->toBe(2)->and($row->status)->toBe('partial');
});
