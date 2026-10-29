<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('folio menu with the manual folio editor', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('S11')]))
        ->click('More actions')
        ->wait(1);

    $this->shoot($page, 'manual-folio-editor-menu', badges: ['Manual Folio Editor…']);
});

test('reason dialog with other', function () {
    $page = $this->open(route('reservations.folio', [$this->property, $this->scenario('S11')]))
        ->click('More actions')
        ->click('Manual Folio Editor…')
        ->waitForText('Choose a reason')
        ->click('#manual-reason')
        ->click('[role="option"]:has-text("Other")')
        ->type('#manual-reason-details', 'Guest paid cash to the night staff')
        ->wait(1);

    $this->shoot($page, 'manual-folio-editor-reason', frame: '[role="dialog"]');
});

/**
 * Open the editor on S11's unit folio with the reason "A charge was missed" and one line filled in.
 */
$openEditor = function () {
    return $this->open(route('reservations.folio', [$this->property, $this->scenario('S11')]), height: 1000)
        ->click('More actions')
        ->click('Manual Folio Editor…')
        ->waitForText('Choose a reason')
        ->click('#manual-reason')
        ->click('[role="option"]:has-text("A charge was missed")')
        ->click('Continue')
        ->waitForText('Add line');
};

test('editor page', function () use ($openEditor) {
    $page = $openEditor->call($this)
        ->click('[aria-label$="item"] >> nth=0')
        ->click('[role="option"] >> nth=0')
        ->type('[aria-label$="description"] >> nth=0', 'Two minibar drinks')
        ->type('[id$="-amount"] >> nth=0', '12.00')
        ->click('[aria-label="Hide summary"]')
        ->wait(1);

    // Typing in the last columns scrolls the wide table sideways. Scroll it back so the first columns show.
    $page->script('document.querySelectorAll("*").forEach((el) => { el.scrollLeft = 0; });');

    $this->shoot($page, 'manual-folio-editor', badges: ['Date', 'Add line', 'Post 1 line']);
});

test('posted lines row menu', function () use ($openEditor) {
    $page = $openEditor->call($this)
        ->click('[role="tab"]:has-text("Posted lines")')
        ->waitForText('Description')
        ->click('[aria-label^="Actions for"] >> nth=0')
        ->wait(1);

    $this->shoot($page, 'manual-folio-editor-row-menu', badges: ['Edit line…', 'Split…', 'Remove tax…', 'Add tax…', 'Recalculate tax']);
});
