<?php

use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    File::ensureDirectoryExists(base_path('tests/Browser/Screenshots/reservations'));
    $this->property = Property::query()->where('name', 'Pearlside View Resort')->firstOrFail();
    $this->actingAs(User::query()->where('email', DemoSeeder::loginEmail('pearlside', DefaultRoleEnum::Owner))->firstOrFail());
});

test('reservation list', function () {
    visit(route('reservations.index', $this->property))
        ->resize(1440, 900)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'reservations/reservation-list');
});

test('new menu', function () {
    visit(route('reservations.index', $this->property))
        ->resize(1440, 900)
        ->click('New')
        ->assertSee('Walk-in')
        ->screenshot(filename: 'reservations/new-menu');
});

test('new booking form', function () {
    visit(route('reservations.new', $this->property))
        ->resize(1440, 900)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'reservations/new-booking');
});

test('new booking form with a priced line', function () {
    visit(route('reservations.new', $this->property))
        ->resize(1440, 900)
        ->click('Pick a date')
        ->click('[role="dialog"] button:text-is("16")')
        ->click('Select room type')
        ->click('Garden Room')
        ->click('Select rate plan')
        ->click('Bed & Breakfast')
        ->waitForText('MYR 480.00')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'reservations/new-booking-priced');
});

test('group reservation page', function () {
    $group = Reservation::query()
        ->where('property_id', $this->property->id)
        ->withCount('units')
        ->orderByDesc('units_count')
        ->firstOrFail();

    visit(route('reservations.show', [$this->property, $group]))
        ->resize(1440, 900)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'reservations/group-reservation');
});

test('edit group dialog', function () {
    $group = Reservation::query()
        ->where('property_id', $this->property->id)
        ->withCount('units')
        ->orderByDesc('units_count')
        ->firstOrFail();

    visit(route('reservations.show', [$this->property, $group]))
        ->resize(1440, 900)
        ->click('More')
        ->click('Edit group')
        ->waitForText('Who pays the room charges')
        ->assertNoJavaScriptErrors()
        ->screenshotElement('[role="dialog"]', filename: 'reservations/edit-group');
});

/**
 * Draws a numbered red badge at the top left of the first element in the main area whose text is exactly the given text. The docs
 * page names each number in a table.
 *
 * @param  list<string>  $texts  The element text for badge 1, 2, 3 and so on.
 */
function numberBadgesScript(array $texts): string
{
    $json = json_encode($texts, JSON_THROW_ON_ERROR);

    return <<<JS
    (() => {
        const texts = {$json};
        texts.forEach((text, index) => {
            const same = (el) => el.textContent.trim() === text;
            const match = [...document.querySelectorAll('body *')].find(
                (el) => same(el)
                    && ![...el.children].some(same)
                    && el.getBoundingClientRect().left >= 256
                    && el.getBoundingClientRect().width > 0,
            );
            if (!match) { return; }
            const box = match.getBoundingClientRect();
            const badge = document.createElement('div');
            badge.textContent = String(index + 1);
            badge.style.cssText = 'position:fixed;z-index:99999;width:22px;height:22px;border-radius:50%;background:#dc2626;color:#fff;font:600 13px/22px sans-serif;text-align:center;box-shadow:0 0 0 2px #fff;pointer-events:none;'
                + 'left:' + Math.max(0, box.left - 14) + 'px;top:' + Math.max(0, box.top - 8) + 'px;';
            document.body.appendChild(badge);
        });
    })();
    JS;
}

function largestGroup(object $test): Reservation
{
    return Reservation::query()
        ->where('property_id', $test->property->id)
        ->withCount('units')
        ->orderByDesc('units_count')
        ->firstOrFail();
}

test('reservation page units tab labelled', function () {
    $group = largestGroup($this);

    $page = visit(route('reservations.show', [$this->property, $group]))
        ->resize(1440, 900)
        ->waitForText('Change price');
    $page->script(numberBadgesScript([
        'Semporna Divers Club',
        'Confirmed',
        'Add unit',
        'More',
        'Units',
        'Sea View Room',
        'Change price',
        'Night',
        'Booker',
        'Still to pay',
    ]));
    $page->assertNoJavaScriptErrors()->screenshot(filename: 'reservations/reservation-page-labelled');
});

test('reservation page guests tab', function () {
    visit(route('reservations.guests', [$this->property, largestGroup($this)]))
        ->resize(1440, 900)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'reservations/reservation-page-guests');
});

test('reservation page folio tab', function () {
    $reservation = Reservation::query()
        ->where('property_id', $this->property->id)
        ->where('reference', 'PVR-26100017')
        ->firstOrFail();

    visit(route('reservations.folio', [$this->property, $reservation]))
        ->resize(1440, 900)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'reservations/reservation-page-folio');
});

test('reservation page folio tab scheduled charges', function () {
    visit(route('reservations.folio', [$this->property, largestGroup($this)]))
        ->resize(1440, 900)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'reservations/reservation-page-folio-scheduled');
});

test('reservation page summary source', function () {
    visit(route('reservations.activity', [$this->property, largestGroup($this)]))
        ->resize(1440, 1100)
        ->screenshot(filename: 'reservations/reservation-page-summary-source');
});

test('reservation page activity tab', function () {
    visit(route('reservations.activity', [$this->property, largestGroup($this)]))
        ->resize(1440, 900)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'reservations/reservation-page-activity');
});

test('reservation page guests tab grouped', function () {
    visit(route('reservations.guests', [$this->property, largestGroup($this)]))
        ->resize(1440, 900)
        ->click('[aria-label="Grouped by unit"]')
        ->waitForText('in this unit')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'reservations/reservation-page-guests-grouped');
});

test('reservation page guest details open', function () {
    $page = visit(route('reservations.guests', [$this->property, largestGroup($this)]))
        ->resize(1440, 900)
        ->click('[aria-label="Show the details of Rachel Lim"]')
        ->waitForText('Scan Card');
    $page->script("[...document.querySelectorAll('button')].find((b) => b.textContent.includes('Scan Card'))?.closest('div.rounded-lg, div.border')?.remove()");
    $page->assertNoJavaScriptErrors()->screenshot(filename: 'reservations/reservation-page-guest-details');
});

function reservationByReference(object $test, string $reference): Reservation
{
    return Reservation::query()
        ->where('property_id', $test->property->id)
        ->where('reference', $reference)
        ->firstOrFail();
}

test('add-ons tab with add-ons', function () {
    visit(route('reservations.add-ons', [$this->property, reservationByReference($this, 'PVR-26100004')]))
        ->resize(1440, 900)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'reservations/add-ons-tab');
});

test('add-ons tab with nightly rows', function () {
    visit(route('reservations.add-ons', [$this->property, reservationByReference($this, 'PVR-26100005')]))
        ->resize(1440, 900)
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'reservations/add-ons-tab-nightly');
});

test('add add-on dialog', function () {
    visit(route('reservations.add-ons', [$this->property, reservationByReference($this, 'PVR-26100004')]))
        ->resize(1440, 900)
        ->click('Add add-on')
        ->click('#add-on-charge_item_id')
        ->click('Airport transfer · Per stay')
        ->assertNoJavaScriptErrors()
        ->screenshotElement('[role="dialog"]', filename: 'reservations/add-add-on');
});

test('change price dialog', function () {
    visit(route('reservations.show', [$this->property, reservationByReference($this, 'PVR-26100017')]))
        ->resize(1440, 900)
        ->click('tbody tr:first-child [data-slot="checkbox"]')
        ->click('Change price')
        ->waitForText('New price per night')
        ->fill('#night-price-price', '250.00')
        ->fill('#night-price-reason', 'Corporate rate agreed late')
        ->waitForText('Difference')
        ->assertNoJavaScriptErrors()
        ->screenshotElement('[role="dialog"]', filename: 'reservations/change-price');
});

test('cancel unit dialog', function () {
    visit(route('reservations.show', [$this->property, largestGroup($this)]))
        ->resize(1440, 900)
        ->click('[aria-label="Unit actions"]')
        ->click('Cancel unit')
        ->waitForText('Cancellation policy')
        ->assertNoJavaScriptErrors()
        ->screenshotElement('[role="dialog"]', filename: 'reservations/cancel-unit');
});

test('mark no-show dialog', function () {
    visit(route('reservations.show', [$this->property, largestGroup($this)]))
        ->resize(1440, 900)
        ->click('[aria-label="Unit actions"]')
        ->click('Mark no-show')
        ->waitForText('No-show policy')
        ->assertNoJavaScriptErrors()
        ->screenshotElement('[role="dialog"]', filename: 'reservations/mark-no-show');
});

test('cancel reservation dialog', function () {
    visit(route('reservations.show', [$this->property, largestGroup($this)]))
        ->resize(1440, 900)
        ->click('More')
        ->click('Cancel reservation')
        ->waitForText('Cancel 4 rooms')
        ->assertNoJavaScriptErrors()
        ->screenshotElement('[role="dialog"]', filename: 'reservations/cancel-reservation');
});
