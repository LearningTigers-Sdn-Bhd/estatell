<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('roles grid', function () {
    $page = $this->open(route('settings.roles.index', $this->property), 1100)
        ->waitForText('Permissions granted to each role');

    $this->shoot($page, 'roles-grid', badges: ['New role'], highlight: ['input[aria-label="Search permissions"]']);
});

test('new role dialog', function () {
    $page = $this->open(route('settings.roles.index', $this->property))
        ->click('New role')
        ->waitForText('A custom role starts with no permissions.')
        ->type('#role-name', 'Night manager');

    $this->shoot($page, 'new-role-dialog', element: '[role="dialog"]');
});

test('change a staff role', function () {
    $page = $this->open(route('settings.staff.index', $this->property))
        ->click('[aria-label="Actions for Farah Front Desk"]')
        ->click('Change role')
        ->waitForText('The new role replaces the old one straight away.');

    $this->shoot($page, 'change-role-dialog', element: '[role="dialog"]');
});
