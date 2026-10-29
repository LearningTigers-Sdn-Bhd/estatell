<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('folio ledger', function () {
    $page = $this->open(route('reports.folio-ledger', ['property' => $this->property, 'from' => '2026-10-09', 'to' => '2026-10-09']), height: 1100)
        ->wait(1);

    $this->shoot($page, 'folio-ledger',
        badges: ['All codes', 'Posting date', 'Guest / payer', 'Kind', 'Staff'],
    );
});
