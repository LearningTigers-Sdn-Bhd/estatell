<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('activity log', function () {
    $page = $this->open(route('reports.activity-log', $this->property), height: 1000)
        ->wait(1);

    $this->shoot($page, 'activity-log',
        badges: ['Time', 'Recorded by', 'Action', 'Record', 'Event'],
        // The Time column uses the browser's time zone, so the server render and the browser differ and React reports a hydration error.
        checkErrors: false,
    );
});
