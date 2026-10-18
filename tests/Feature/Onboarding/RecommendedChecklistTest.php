<?php

use App\Data\Onboarding\RecommendedItemData;
use App\Enums\OnboardingStep;
use App\Enums\PermissionEnum;
use App\Enums\PolicyChargeMode;
use App\Enums\PolicyScope;
use App\Enums\SurchargeType;
use App\Enums\SystemTransactionCode;
use App\Models\ChargeItem;
use App\Models\Company;
use App\Models\OnboardingSkip;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\RecommendedItemDismissal;
use App\Models\RoomGroup;
use App\Models\RoomRevenuePolicy;
use App\Models\User;
use App\Services\Onboarding\RecommendedChecklist;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');

    $this->owner = User::factory()->owner()->for(Property::factory()->live())->create();
    $this->property = $this->owner->property;
});

function recommendedKeys(Property $property): array
{
    return array_map(fn ($item): string => $item->key, app(RecommendedChecklist::class)->forProperty($property)?->items ?? []);
}

function recommendedItem(Property $property, string $key): RecommendedItemData
{
    return collect(app(RecommendedChecklist::class)->forProperty($property)->items)->firstWhere('key', $key);
}

test('a new property has the five open items in the doc order', function () {
    $checklist = app(RecommendedChecklist::class)->forProperty($this->property);

    expect(array_map(fn ($item): string => $item->key, $checklist->items))
        ->toBe(['logo', 'room_revenue', 'missed_arrivals', 'payment_surcharges', 'room_groups'])
        ->and($checklist->done)->toBe(0)
        ->and($checklist->total)->toBe(5)
        ->and(recommendedItem($this->property, 'logo')->actionUrl)->toBe(route('settings.profile', $this->property))
        ->and(recommendedItem($this->property, 'missed_arrivals')->done)->toBeFalse();
});

test('the property logo is done once a logo is uploaded', function () {
    $this->property->addMedia(UploadedFile::fake()->image('logo.png'))->toMediaCollection(Property::LOGO);

    expect(recommendedItem($this->property, 'logo')->done)->toBeTrue()
        ->and(recommendedItem($this->property, 'logo')->dismissible)->toBeFalse();
});

test('no-show and cancellation charges are done only when a policy is not Free', function () {
    RoomRevenuePolicy::factory()->for($this->property)->create([
        'code' => SystemTransactionCode::NoShowRevenue,
        'applies_to' => PolicyScope::FirstNight,
        'charge_mode' => PolicyChargeMode::Free,
    ]);
    RoomRevenuePolicy::factory()->for($this->property)->create([
        'code' => SystemTransactionCode::EarlyDepartureRevenue,
        'applies_to' => PolicyScope::UnusedNights,
        'charge_mode' => PolicyChargeMode::FixedAmount,
        'amount' => '50.00',
    ]);

    expect(recommendedItem($this->property, 'room_revenue')->done)->toBeFalse();

    RoomRevenuePolicy::query()->where('code', SystemTransactionCode::NoShowRevenue)->update(['charge_mode' => PolicyChargeMode::FixedAmount->value, 'amount' => '50.00']);

    expect(recommendedItem($this->property, 'room_revenue')->done)->toBeTrue();
});

test('a payment method surcharge makes that item done', function () {
    PaymentMethod::factory()->for($this->property)->create(['surcharge_type' => SurchargeType::None]);

    expect(recommendedItem($this->property, 'payment_surcharges')->done)->toBeFalse();

    PaymentMethod::factory()->for($this->property)->create([
        'surcharge_type' => SurchargeType::Percent,
        'surcharge_value' => '3.00',
        'surcharge_charge_item_id' => ChargeItem::factory()->for($this->property)->create()->id,
    ]);

    expect(recommendedItem($this->property, 'payment_surcharges')->done)->toBeTrue();
});

test('a room group makes that item done and names the groups', function () {
    expect(recommendedItem($this->property, 'room_groups')->done)->toBeFalse();

    RoomGroup::factory()->for($this->property)->create(['name' => 'Sea view', 'position' => 1]);

    expect(recommendedItem($this->property, 'room_groups')->done)->toBeTrue()
        ->and(recommendedItem($this->property, 'room_groups')->summary)->toBe('Sea view');
});

test('a skipped section is an item until it gets data, and the tax skip is not', function () {
    OnboardingSkip::factory()->create(['property_id' => $this->property->id, 'step' => OnboardingStep::Companies]);
    OnboardingSkip::factory()->create(['property_id' => $this->property->id, 'step' => OnboardingStep::Taxes]);

    expect(recommendedKeys($this->property))->toContain('skipped:companies')
        ->not->toContain('skipped:taxes')
        ->and(recommendedItem($this->property, 'skipped:companies')->done)->toBeFalse();

    Company::factory()->for($this->property)->create();

    expect(recommendedItem($this->property, 'skipped:companies')->done)->toBeTrue();
});

test('dismissed items are not listed, and another property dismissal does not hide them', function () {
    RecommendedItemDismissal::factory()->create(['property_id' => $this->property->id, 'item' => 'room_groups']);
    RecommendedItemDismissal::factory()->create(['property_id' => Property::factory()->live()->create()->id, 'item' => 'missed_arrivals']);

    expect(recommendedKeys($this->property))
        ->toBe(['logo', 'room_revenue', 'missed_arrivals', 'payment_surcharges']);
});

test('the checklist is gone when every item is done or dismissed', function () {
    $this->property->addMedia(UploadedFile::fake()->image('logo.png'))->toMediaCollection(Property::LOGO);
    RecommendedItemDismissal::factory()->create(['property_id' => $this->property->id, 'item' => 'room_revenue']);
    RecommendedItemDismissal::factory()->create(['property_id' => $this->property->id, 'item' => 'missed_arrivals']);
    RecommendedItemDismissal::factory()->create(['property_id' => $this->property->id, 'item' => 'payment_surcharges']);
    RecommendedItemDismissal::factory()->create(['property_id' => $this->property->id, 'item' => 'room_groups']);

    expect(app(RecommendedChecklist::class)->forProperty($this->property))->toBeNull();
});

test('only the dismissable keys can be marked as not needed', function (string $key, bool $expected) {
    expect(RecommendedChecklist::isDismissibleKey($key))->toBe($expected);
})->with([
    'logo' => ['logo', false],
    'room groups' => ['room_groups', true],
    'missed arrivals' => ['missed_arrivals', true],
    'an optional section' => ['skipped:companies', true],
    'a required section' => ['skipped:rooms', false],
    'the tax skip' => ['skipped:taxes', false],
    'an unknown section' => ['skipped:bogus', false],
    'an unknown key' => ['bogus', false],
]);

test('the dashboard sends the checklist only to users who manage settings', function () {
    $this->actingAs($this->owner)
        ->get(route('dashboard', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('recommended.total', 5)->where('recommended.done', 0));

    $frontDesk = User::factory()->for($this->property)->withPermissions([PermissionEnum::ReservationsView])->create();

    $this->actingAs($frontDesk)
        ->get(route('dashboard', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('recommended', null));
});
