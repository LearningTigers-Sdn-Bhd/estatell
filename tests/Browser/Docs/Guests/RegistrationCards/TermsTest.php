<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
    $this->documentUrl = route('settings.registration-card.terms.documents.edit', [
        $this->property,
        $this->property->termsDocuments()->where('is_default', true)->firstOrFail(),
    ]);
});

test('terms tab', function () {
    $page = $this->open(route('settings.registration-card.terms', $this->property), height: 900)
        ->assertSee('New document');

    $page->script('document.fonts.ready.then(() => true)');

    $this->shoot($page, 'terms-list', badges: [
        'New document',
        'Search documents',
        'Languages',
    ]);
});

test('add a document', function () {
    $page = $this->open(route('settings.registration-card.terms', $this->property), height: 900)
        ->click('New document')
        ->waitForText('New terms document');

    $this->shoot($page, 'add-document', frame: '[role="dialog"]');
});

test('terms document', function () {
    $page = $this->open($this->documentUrl, height: 1300)
        ->assertSee('Terms text');

    $page->script('document.fonts.ready.then(() => true)');

    $this->shoot($page, 'terms', badges: [
        'More actions',
        'Terms text',
        'Preview',
    ]);
});

test('version history', function () {
    $page = $this->open($this->documentUrl, height: 1300)
        ->click('More actions')
        ->click('Version history')
        ->waitForText('Use as starting point');

    $this->shoot($page, 'version-history', frame: '[role="dialog"]');
});
