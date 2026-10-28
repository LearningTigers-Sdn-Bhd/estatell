<?php

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
});

test('add staff menu', function () {
    $page = $this->open(route('settings.staff.index', $this->property))
        ->click('Add staff')
        ->waitForText('Invite by email');

    $this->shoot($page, 'add-staff-menu');
});

test('invite by email dialog', function () {
    $page = $this->open(route('settings.staff.index', $this->property))
        ->click('Add staff')
        ->click('Invite by email')
        ->waitForText('The link works for 7 days.')
        ->type('#invite-email', 'nadia@pearlside.my');

    $this->shoot($page, 'invite-dialog', frame: '[role="dialog"]');
});

test('add with temp password dialog', function () {
    $page = $this->open(route('settings.staff.index', $this->property))
        ->click('Add staff')
        ->click('Add with temp password')
        ->waitForText('Add staff with a temp password')
        ->type('#add-name', 'Ali Hassan')
        ->type('#add-email', 'ali@pearlside.my');

    $this->shoot($page, 'temp-password-dialog', frame: '[role="dialog"]');
});

test('sign-in message', function () {
    $page = $this->open(route('settings.staff.index', $this->property))
        ->click('Add staff')
        ->click('Add with temp password')
        ->waitForText('Add staff with a temp password')
        ->type('#add-name', 'Ali Hassan')
        ->type('#add-email', 'ali@pearlside.my')
        ->click('#add-role')
        ->click('[role="option"]:has-text("Front desk")')
        ->click('[role="dialog"] button[type="submit"]')
        ->waitForText('Sign-in message');

    $this->shoot($page, 'sign-in-message', frame: '[role="dialog"]:has-text("Sign-in message")');
});

test('bulk add staff', function () {
    $page = $this->open(route('settings.staff.bulk.create', $this->property))
        ->waitForText('Up to 200 people.');

    $this->shoot($page, 'bulk-add', badges: ['Upload CSV / XLSX', 'Template']);
});
