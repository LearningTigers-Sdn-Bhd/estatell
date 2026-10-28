<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('age groups tab', function () {
    $page = $this->open(route('settings.age-groups', $this->property->code))
        ->waitForText('Save age groups');

    $this->shoot($page, 'age-groups', badges: ['Label', 'From', 'To', 'Add age group']);
});
