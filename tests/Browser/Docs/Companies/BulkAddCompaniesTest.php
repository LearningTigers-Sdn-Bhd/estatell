<?php

beforeEach(function () {
    $this->signInAsScenariosOwner();
});

test('the bulk add page', function () {
    $page = $this->open(route('finance.city-ledger.companies.bulk.create', $this->property))
        ->waitForText('Bulk add companies')
        ->wait(1);

    $this->shoot($page, 'bulk-add-companies-page', badges: ['Simple', 'Detailed', 'Upload CSV / XLSX', 'Template', 'Add row']);
});

test('the detailed columns', function () {
    $page = $this->open(route('finance.city-ledger.companies.bulk.create', $this->property))
        ->waitForText('Bulk add companies')
        ->click('Detailed')
        ->waitForText('Address line 1')
        ->wait(1);

    $this->shoot($page, 'bulk-add-companies-detailed', badges: ['Detailed', 'BRN']);
});

test('the template menu', function () {
    $page = $this->open(route('finance.city-ledger.companies.bulk.create', $this->property))
        ->waitForText('Bulk add companies')
        ->click('Template')
        ->waitForText('Excel (XLSX)')
        ->wait(1);

    $this->shoot($page, 'bulk-add-companies-template', badges: ['CSV', 'Excel (XLSX)']);
});

test('a file with rows to check', function () {
    $path = sys_get_temp_dir().'/companies-'.uniqid().'.csv';
    $handle = fopen($path, 'w');

    foreach ([
        ['name', 'type', 'market', 'email', 'credit_limit', 'terms_days', 'direct_billing'],
        ['Sabah Marine Tours', 'Travel agent', 'international', 'bookings@sabahmarine.test', '20000', '30', 'yes'],
        ['Jabatan Kerja Raya Sabah', 'government', 'local', 'akaun@jkr-sabah.test', '', '60', ''],
        ['Petronas Carigali Miri', 'Corporate', 'local', 'travel@petronas-miri.test', '50000', '45', 'no'],
        ['Kota Kinabalu Airlines', 'Airline', '', '', '', '', 'maybe'],
    ] as $line) {
        fputcsv($handle, $line);
    }

    fclose($handle);

    $page = $this->open(route('finance.city-ledger.companies.bulk.create', $this->property), 1000)
        ->waitForText('Bulk add companies')
        ->attach('input[type="file"]', $path)
        ->waitForText('Create 4 companies')
        ->wait(1);

    $this->shoot($page, 'bulk-add-companies-file', badges: ['Create 4 companies']);
});
