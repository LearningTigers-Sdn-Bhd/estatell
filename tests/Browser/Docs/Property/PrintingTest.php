<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('general tab', function () {
    $page = $this->open(route('settings.printing.general', $this->property))
        ->assertSee('Font style');

    $page->script('document.fonts.ready.then(() => true)');

    $this->shoot($page, 'printing-general');
});

test('header tab', function () {
    $page = $this->open(route('settings.printing.header', $this->property))
        ->assertSee('Header lines');

    $page->script('document.fonts.ready.then(() => true)');

    $this->shoot($page, 'printing-header');
});

test('documents tab', function () {
    $page = $this->open(route('settings.printing.documents', $this->property), height: 1100)
        ->assertSee('Payment details');

    $page->script('document.fonts.ready.then(() => true)');

    $this->shoot($page, 'printing-documents');
});
