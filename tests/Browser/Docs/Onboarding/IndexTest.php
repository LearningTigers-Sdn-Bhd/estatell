<?php

test('the wizard opens on the first section of a new property', function () {
    $property = $this->startOnboarding();

    $page = $this->open(route('onboarding.basics.edit', $property->code))
        ->waitForText('Basics');

    $this->shoot($page, 'wizard-start');
});
