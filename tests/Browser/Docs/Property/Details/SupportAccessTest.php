<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('support access tab', function () {
    $page = $this->open(route('settings.support', $this->property->code))
        ->waitForText('Allow platform support access');

    $this->shoot($page, 'support-access', badges: [
        'Allow platform support access',
        'Allow support to refund, void and issue credit notes',
    ]);
});
