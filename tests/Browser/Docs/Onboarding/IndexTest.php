<?php

beforeEach(function () {
    $this->startOnboarding();
});

test('phase bar and section tabs', function () {
    $page = $this->open(route('onboarding.basics.edit', $this->property->code), height: 330)
        ->waitForText('Property basics');

    $this->shoot($page, 'phase-bar');
});

test('locked section preview', function () {
    $page = $this->open(route('onboarding.room-types.edit', $this->property->code), height: 560)
        ->waitForText('Finish Basics first');

    $this->shoot($page, 'locked-preview');
});
