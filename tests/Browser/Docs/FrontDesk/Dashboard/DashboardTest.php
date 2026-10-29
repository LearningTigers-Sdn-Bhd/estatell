<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('today tab', function () {
    $page = $this->open(route('dashboard', [$this->property, 'tab' => 'today']))
        ->waitForText('To do today')
        ->wait(2);

    $this->shoot($page, 'today', badges: ['Run night audit', 'New booking', 'to check in', 'to check out', '5 to inspect ·', '132', 'To do today', 'Check out', '6 rooms free · 3 beds free', 'Recommended next']);
});
