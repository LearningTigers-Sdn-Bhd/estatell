<?php

use App\Models\RatePlanInclusion;

test('meal counts', function () {
    $this->signInAsScenariosOwner();

    $page = $this->open(route('reports.meal-counts', [$this->property, 'from' => '2026-10-14', 'to' => '2026-10-20']), height: 1000)
        ->waitForText('Meal counts');

    $this->shoot($page, 'meal-counts', badges: ['Next 7 days', 'Meal', 'Adults', 'Children'], highlight: ['tfoot']);
});

test('meal counts with several meals a day', function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');

    $page = $this->open(route('reports.meal-counts', [$this->property, 'from' => '2026-10-14', 'to' => '2026-10-17']))
        ->waitForText('Meal counts');

    $this->shoot($page, 'meal-counts-meals');
});

test('meal counts quick dates', function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');

    $page = $this->open(route('reports.meal-counts', $this->property))
        ->waitForText('Meal counts')
        ->click('[aria-label="Quick dates"]')
        ->wait(1);

    $this->shoot($page, 'meal-counts-quick-dates');
});

test('meal counts when no rate plan includes meals', function () {
    $this->signInAsScenariosOwner();

    RatePlanInclusion::query()->whereHas('ratePlan', fn ($plans) => $plans->where('property_id', $this->property->id))->delete();

    $page = $this->open(route('reports.meal-counts', $this->property))
        ->waitForText('No rate plan includes meals');

    $this->shoot($page, 'meal-counts-empty');
});
