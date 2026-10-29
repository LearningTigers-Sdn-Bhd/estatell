<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('general tab', function () {
    $page = $this->open(route('settings.registration-card.general', $this->property), height: 1000)
        ->assertSee('Who completes a card');

    $page->script('document.fonts.ready.then(() => true)');

    $this->shoot($page, 'general', badges: [
        'Who completes a card',
        'Require card before check-in',
        'ID document retention (days)',
        'Security deposit',
        'Save changes',
    ]);
});
