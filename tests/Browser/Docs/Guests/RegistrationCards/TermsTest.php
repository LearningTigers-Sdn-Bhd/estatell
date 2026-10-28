<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('terms tab', function () {
    $page = $this->open(route('settings.registration-card.terms', $this->property), height: 1300)
        ->assertSee('Publish new version');

    $page->script('document.fonts.ready.then(() => true)');

    $this->shoot($page, 'terms', badges: [
        'Add document',
        'Terms text',
        'Preview',
        'Version history',
        'Publish new version',
    ]);
});

test('add a document', function () {
    $page = $this->open(route('settings.registration-card.terms', $this->property), height: 1300)
        ->click('Add document')
        ->waitForText('Add terms document');

    $this->shoot($page, 'add-document', frame: '[role="dialog"]');
});

test('version history', function () {
    $page = $this->open(route('settings.registration-card.terms', $this->property), height: 1300)
        ->click('Version history')
        ->waitForText('Use as starting point');

    $this->shoot($page, 'version-history', frame: '[role="dialog"]');
});
