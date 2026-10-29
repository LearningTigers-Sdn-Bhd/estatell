<?php

use App\Actions\Property\SaveAgeGroups;
use App\Actions\Rates\CreateRatePlan;
use App\Enums\PricingBasis;
use App\Models\RatePlan;

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('children tab', function () {
    $roomTypeIds = $this->property->roomTypes()->whereIn('name', ['Garden Room', 'Sea View Room'])->pluck('id')->all();
    $plan = app(CreateRatePlan::class)($this->property, auth()->user(), [
        'name' => 'Per Person Rate',
        'description' => 'A price for each adult.',
        'pricing_basis' => PricingBasis::PerGuest->value,
    ], $roomTypeIds);

    $groups = $this->property->ageGroups()->ordered()->get()
        ->map(fn ($group): array => ['id' => $group->id, 'label' => $group->label, 'max_age' => $group->max_age])
        ->push(['id' => null, 'label' => 'Teen', 'max_age' => 15])
        ->all();
    app(SaveAgeGroups::class)($this->property, auth()->user(), $groups);

    $page = $this->open(route('settings.rate-plans.child-prices.edit', [$this->property, $plan]))
        ->waitForText('Save child prices');

    $this->shoot($page, 'children-tab', badges: ['Check', 'Age group', 'Price', 'Value']);
});

test('check badge in the list', function () {
    $roomTypeIds = $this->property->roomTypes()->whereIn('name', ['Garden Room', 'Sea View Room'])->pluck('id')->all();
    app(CreateRatePlan::class)($this->property, auth()->user(), [
        'name' => 'Per Person Rate',
        'pricing_basis' => PricingBasis::PerGuest->value,
    ], $roomTypeIds);

    $groups = $this->property->ageGroups()->ordered()->get()
        ->map(fn ($group): array => ['id' => $group->id, 'label' => $group->label, 'max_age' => $group->max_age])
        ->push(['id' => null, 'label' => 'Teen', 'max_age' => 15])
        ->all();
    app(SaveAgeGroups::class)($this->property, auth()->user(), $groups);

    $page = $this->open(route('settings.rate-plans.index', $this->property))
        ->waitForText('Check child prices');

    $this->shoot($page, 'check-child-prices', badges: ['Check child prices']);
});

test('package charged per guest', function () {
    $plan = RatePlan::query()->where('property_id', $this->property->id)->where('name', 'Dive Package')->firstOrFail();

    $page = $this->open(route('settings.rate-plans.child-prices.edit', [$this->property, $plan]))
        ->waitForText('Save child prices');

    $this->shoot($page, 'package-children-tab');
});
