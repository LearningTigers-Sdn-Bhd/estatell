<?php

use App\Actions\Onboarding\SavePropertyBasics;
use App\Actions\Property\ChangePropertyCountry;
use App\Enums\OnboardingStep;
use App\Enums\PropertyStatus;
use App\Enums\StepState;
use App\Models\ChargeItem;
use App\Models\Discount;
use App\Models\GoLiveReview;
use App\Models\OnboardingSkip;
use App\Models\Property;
use App\Models\Rate;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Onboarding\BasicRateRows;
use App\Services\Onboarding\OnboardingProgress;
use App\Services\Property\CountryProfiles;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
    $input = basicsInput();
    app(SavePropertyBasics::class)($this->owner, $this->property, [
        'type' => $this->property->type,
        'name' => $input['name'],
        'address_line1' => $input['address_line1'],
        'city' => $input['city'],
        'country_code' => 'MY',
    ], ['legal_name' => null, 'registration_number' => null, 'tin' => null], []);
    $this->property->refresh();

    $roomType = RoomType::factory()->for($this->property)->create(['name' => 'Garden Suite']);
    Room::factory()->for($this->property)->create(['room_type_id' => $roomType->id, 'number' => '101']);
    $this->basicPlan = $this->property->ratePlans()->sole();
    $this->basicPlan->roomTypes()->attach($roomType);
    $this->basicPlan->update(['extra_adult_amount' => '25.00']);

    foreach (range(0, 29) as $day) {
        Rate::factory()->create([
            'room_type_id' => $roomType->id,
            'rate_plan_id' => $this->basicPlan->id,
            'date' => now()->addDays($day)->toDateString(),
            'amount' => '180.00',
        ]);
    }

    Discount::query()->where('property_id', $this->property->id)->update(['type' => 'fixed', 'value' => '20.00']);
    ChargeItem::query()->where('property_id', $this->property->id)->first()->update(['default_amount' => '15.00']);

    OnboardingSkip::factory()->for($this->property)->create(['step' => OnboardingStep::Companies, 'skipped_by' => $this->owner->id]);
    OnboardingSkip::factory()->for($this->property)->create(['step' => OnboardingStep::Taxes, 'skipped_by' => $this->owner->id]);

    $this->change = fn (string $code, ?string $currency = null, ?string $zone = null): bool => app(ChangePropertyCountry::class)(
        $this->owner,
        $this->property->refresh(),
        $code,
        $currency,
        $zone,
    );
});

test('after setup started no Malaysian preset key, tax link or old-currency amount is left', function () {
    $malaysianOnly = array_values(array_diff(
        array_map(fn ($code) => $code->value, CountryProfiles::for('MY')->transactionCodes()),
        array_map(fn ($code) => $code->value, CountryProfiles::for('TH')->transactionCodes()),
    ));

    expect($malaysianOnly)->toContain('sst_tax', 'tourism_tax');

    expect(($this->change)('TH', 'THB', 'Asia/Bangkok'))->toBeTrue();

    $property = $this->property->refresh();

    expect($property->transactionCodes()->whereIn('system_key', $malaysianOnly)->exists())->toBeFalse()
        ->and($property->taxes()->count())->toBe(0)
        ->and(DB::table('tax_transaction_code')->count())->toBe(0)
        ->and(DB::table('print_setting_taxes')->count())->toBe(0)
        ->and(DB::table('rates')->whereNotNull('amount')->count())->toBe(0)
        ->and($this->basicPlan->refresh()->extra_adult_amount)->toEqual(0)
        ->and($property->discounts()->where('type', 'fixed')->exists())->toBeFalse()
        ->and($property->chargeItems()->whereNotNull('default_amount')->exists())->toBeFalse()
        ->and($property->settings->currency->getCurrencyCode())->toBe('THB')
        ->and($property->settings->time_zone)->toBe('Asia/Bangkok')
        ->and($property->settings->security_deposit_amount)->toEqual(0);
});

test('the basic rate loses its prices and is no longer Done', function () {
    expect(app(OnboardingProgress::class)->state($this->property->refresh(), OnboardingStep::BasicRate))->toBe(StepState::Done);

    ($this->change)('TH', 'THB', 'Asia/Bangkok');

    $property = $this->property->refresh();
    $rows = app(BasicRateRows::class)->forProperty($property)['rows'];

    expect(app(OnboardingProgress::class)->state($property, OnboardingStep::BasicRate))->not->toBe(StepState::Done)
        ->and($rows->every(fn ($row) => $row->hasSavedPrices === false))->toBeTrue();
});

test('it keeps the identity, room, rate plan, team and check-in data', function () {
    $roomTypeCount = $this->property->roomTypes()->count();
    $roomCount = $this->property->rooms()->count();
    $roles = $this->property->roles()->count();
    $cardFields = $this->property->registrationCardFields()->count();
    $planRoomTypes = DB::table('rate_plan_room_types')->where('rate_plan_id', $this->basicPlan->id)->count();

    ($this->change)('TH', 'THB', 'Asia/Bangkok');

    $property = $this->property->refresh();

    expect($property->name)->toBe('Seaview Guesthouse')
        ->and($property->address_line1)->toBe('12 Jalan Pantai')
        ->and($property->roomTypes()->count())->toBe($roomTypeCount)
        ->and($property->rooms()->count())->toBe($roomCount)
        ->and($property->ratePlans()->whereKey($this->basicPlan->id)->exists())->toBeTrue()
        ->and(DB::table('rate_plan_room_types')->where('rate_plan_id', $this->basicPlan->id)->count())->toBe($planRoomTypes)
        ->and($property->roles()->count())->toBe($roles)
        ->and($property->registrationCardFields()->count())->toBe($cardFields)
        ->and($property->settings->check_in_time)->toBe('15:00:00');
});

test('the skips of the reset sections go and the others stay', function () {
    ($this->change)('TH', 'THB', 'Asia/Bangkok');

    $skips = OnboardingSkip::query()->where('property_id', $this->property->id)->pluck('step')->map->value->all();

    expect($skips)->toBe(['companies']);
});

test('the reset counts match what was removed and are logged', function () {
    ($this->change)('TH', 'THB', 'Asia/Bangkok');

    $logged = Activity::query()->where('event', 'property.country-changed')->sole()->properties->all();

    expect($logged['setup_started'])->toBeTrue()
        ->and($logged['reset'])->toMatchArray([
            'taxes' => 2,
            'paymentMethods' => 6,
            'chargeItems' => 5,
            'discounts' => 1,
            'prices' => 31,
            'inclusions' => 0,
            'termsDocuments' => 1,
            'skippedSteps' => 1,
        ]);
});

test('the terms document is replaced and room types lose their link to it', function () {
    $oldDocument = $this->property->termsDocuments()->sole();
    $this->property->roomTypes()->first()->update(['terms_document_id' => $oldDocument->id]);

    ($this->change)('TH', 'THB', 'Asia/Bangkok');

    $property = $this->property->refresh();

    expect($property->termsDocuments()->whereKey($oldDocument->id)->exists())->toBeFalse()
        ->and($property->termsDocuments()->count())->toBe(1)
        ->and($property->roomTypes()->whereNotNull('terms_document_id')->exists())->toBeFalse();
});

test('a live property is refused after setup started, and nothing is written', function () {
    $this->property->forceFill(['went_live_at' => now(), 'status' => PropertyStatus::Live])->save();

    expect(($this->change)('TH', 'THB', 'Asia/Bangkok'))->toBeFalse();

    $property = $this->property->refresh();

    expect($property->country_code)->toBe('MY')
        ->and($property->taxes()->count())->toBe(2)
        ->and(Activity::query()->where('event', 'property.country-changed')->exists())->toBeFalse();
});

test('a property with a pending go-live request is refused, and nothing is written', function () {
    GoLiveReview::factory()->for($this->property)->create();
    $this->property->forceFill(['status' => PropertyStatus::PendingApproval])->save();

    expect(($this->change)('TH', 'THB', 'Asia/Bangkok'))->toBeFalse();

    $property = $this->property->refresh();

    expect($property->country_code)->toBe('MY')
        ->and($property->taxes()->count())->toBe(2)
        ->and($property->discounts()->where('type', 'fixed')->exists())->toBeTrue();
});
