<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('layout tab', function () {
    $page = $this->open(route('settings.registration-card.layout', $this->property), height: 1500)
        ->assertSee('Changes save automatically.');

    $page->script('document.fonts.ready.then(() => true)');

    $this->shoot($page, 'layout', badges: [
        'Visibility',
        'Required',
        'Changes save automatically.',
    ], highlight: ['aside[aria-label="Registration card preview"]']);
});
