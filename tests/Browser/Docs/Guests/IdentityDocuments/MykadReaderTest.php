<?php

use App\Models\Guest;

/**
 * The real bridge is a program on the front-desk PC, so these tests never call it. They replace the browser's
 * `fetch` for 127.0.0.1:9292 with a fixed answer, the same JSON the bridge sends, so every run looks the same.
 *
 * @param  array<string, mixed>  $status  What `GET /status` returns.
 */
function mykadBridgeStub(array $status, bool $withCard = false): string
{
    $statusJson = json_encode($status, JSON_THROW_ON_ERROR);
    $read = json_encode(['success' => true, 'data' => [
        'name' => 'NUR AISYAH BINTI RAHMAN',
        'ic_number' => '900101141234',
        'formatted_ic' => '900101-14-1234',
        'date_of_birth' => '1990-01-01',
        'gender' => 'female',
        'address_line1' => '12 JALAN MAWAR',
        'city' => 'KOTA KINABALU',
        'state' => 'Sabah',
        'postal_code' => '88000',
    ]], JSON_THROW_ON_ERROR);
    $card = $withCard ? 'true' : 'false';

    return <<<JS
    (() => {
        const real = window.fetch.bind(window);
        const portrait = 'data:image/svg+xml;base64,' + btoa('<svg xmlns="http://www.w3.org/2000/svg" width="96" height="112"><rect width="96" height="112" fill="#cbd5e1"/><circle cx="48" cy="42" r="20" fill="#f1f5f9"/><ellipse cx="48" cy="104" rx="34" ry="30" fill="#f1f5f9"/></svg>');
        window.fetch = (url, ...rest) => {
            const target = String(url);
            if (!target.includes('127.0.0.1:9292')) { return real(url, ...rest); }
            if (target.endsWith('/read') && {$card}) {
                const body = {$read};
                body.data.photo_base64 = portrait;
                return Promise.resolve(new Response(JSON.stringify(body), { status: 200 }));
            }
            return Promise.resolve(new Response(JSON.stringify({$statusJson}), { status: 200 }));
        };
    })();
    JS;
}

beforeEach(function () {
    $this->signInAsDemoOwner('pearlside', 'Pearlside View Resort');
    $this->guest = Guest::factory()->create([
        'property_id' => $this->property->id,
        'full_name' => 'Aisyah binti Rahman',
        'email' => 'aisyah.rahman@example.my',
    ]);
});

test('reader ready', function () {
    $page = $this->open(route('guests.documents.index', [$this->property, $this->guest]))
        ->waitForText('No ID documents yet');
    $page->script(mykadBridgeStub(['reader_connected' => true, 'reader_name' => 'ACR39U', 'card_present' => false, 'version' => '1.1.0']));
    $page->click('Add document')
        ->waitForText('ACR39U ready')
        ->wait(1);

    $this->shoot($page, 'reader-ready', frame: '[role="dialog"]');
});

test('card read', function () {
    $page = $this->open(route('guests.documents.index', [$this->property, $this->guest]))
        ->waitForText('No ID documents yet');
    $page->script(mykadBridgeStub(['reader_connected' => true, 'reader_name' => 'ACR39U', 'card_present' => true, 'version' => '1.1.0'], withCard: true));
    $page->click('Add document')
        ->waitForText('Scanned')
        ->wait(1);

    $this->shoot($page, 'card-read', frame: '[role="dialog"]');
});

test('bridge update', function () {
    $page = $this->open(route('guests.documents.index', [$this->property, $this->guest]))
        ->waitForText('No ID documents yet');
    $page->script(mykadBridgeStub(['reader_connected' => true, 'reader_name' => 'ACR39U', 'card_present' => false, 'version' => '1.0.0']));
    $page->click('Add document')
        ->waitForText('Bridge update available')
        ->wait(1);

    $this->shoot($page, 'bridge-update', frame: '[role="dialog"]');
});
