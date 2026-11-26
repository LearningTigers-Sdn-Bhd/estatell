<?php

use App\Enums\UnitStatus;
use App\Features\Concierge;
use App\Models\BookingPreCheckInToken;
use App\Models\Property;
use App\Models\ReservationUnit;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/ConciergeSetup.php';

function conciergeFind(string $code, string $reference): TestResponse
{
    return test()->post("/stay/{$code}", ['reference' => $reference]);
}

/** `conciergeSetup` with Check in by name on. */
function conciergeNameSetup(array $unit = []): array
{
    $setup = conciergeSetup($unit);
    $setup['property']->settings->forceFill(['concierge_name_check_in_enabled' => true])->save();

    return $setup;
}

it('shows the lobby of a property that has the Concierge feature on', function () {
    ['property' => $property] = conciergeSetup();

    $this->get("/stay/{$property->code}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('concierge/lobby')
        ->where('property.code', $property->code));
});

it('serves the property logo to a guest who is not logged in', function () {
    ['property' => $property] = conciergeSetup();
    $property->addMedia(UploadedFile::fake()->image('logo.png'))->toMediaCollection(Property::LOGO);

    $this->get("/stay/{$property->code}")->assertInertia(fn (Assert $page) => $page
        ->where('property.logoUrl', fn (string $url) => str_starts_with($url, "/stay/{$property->code}/logo")));
    $this->get("/stay/{$property->code}/logo")->assertOk();
});

it('answers 404 for the lobby while the feature is off or the property is suspended', function () {
    ['property' => $property] = conciergeSetup();

    Feature::for($property)->deactivate(Concierge::class);
    $this->get("/stay/{$property->code}")->assertNotFound();

    Feature::for($property)->activate(Concierge::class);
    $property->forceFill(['suspended_at' => now()])->save();
    $this->get("/stay/{$property->code}")->assertNotFound();
});

it('opens the stay by booking code, ignoring case and spaces', function (string $reference) {
    ['property' => $property, 'unit' => $unit] = conciergeSetup();
    BookingPreCheckInToken::query()->delete();

    $response = conciergeFind($property->code, $reference)->assertRedirect();

    $token = BookingPreCheckInToken::query()->where('reservation_unit_id', $unit->id)->sole();
    expect(hash('sha256', basename($response->headers->get('Location'))))->toBe($token->token_hash);
})->with([['R100'], ['r100'], [' R100 ']]);

it('gives the same answer for any code that opens nothing, and issues no link', function () {
    ['property' => $property] = conciergeSetup();
    $before = BookingPreCheckInToken::query()->count();

    $response = conciergeFind($property->code, 'R999')->assertSessionHasErrors('reference');

    expect($response->getSession()->get('errors')->first('reference'))->toContain('could not find')
        ->and(BookingPreCheckInToken::query()->count())->toBe($before);
});

it('does not open a stay of another property', function () {
    ['property' => $property] = conciergeSetup();
    kioskStayFor(Property::factory()->create(), 'R-200')['reservation']->forceFill(['short_code' => 'R200'])->save();

    conciergeFind($property->code, 'R200')->assertSessionHasErrors('reference');
});

it('opens nothing when the code matches more than one room', function () {
    ['property' => $property, 'unit' => $unit] = conciergeSetup();
    $second = ReservationUnit::factory()->create(['reservation_id' => $unit->reservation_id, 'room_type_id' => $unit->room_type_id, 'arrival_date' => '2026-10-14', 'departure_date' => '2026-10-16']);
    $unit->guests()->first()->replicate(['id'])->forceFill(['reservation_unit_id' => $second->id])->save();

    conciergeFind($property->code, 'R100')->assertSessionHasErrors('reference');
});

it('locks a booking code after repeated wrong tries from the same address, even for the right code', function () {
    ['property' => $property] = conciergeSetup();

    foreach (range(1, 8) as $number) {
        conciergeFind($property->code, "R9{$number}");
    }

    $response = conciergeFind($property->code, 'R100')->assertSessionHasErrors('reference');
    expect($response->getSession()->get('errors')->first('reference'))->toContain('Too many tries');
});

it('locks one booking code against guesses spread over many addresses', function () {
    ['property' => $property] = conciergeSetup();

    foreach (range(1, 5) as $number) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$number}"])->post("/stay/{$property->code}", ['reference' => 'R100x']);
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])->post("/stay/{$property->code}", ['reference' => 'R100x'])->assertSessionHasErrors('reference');
    expect($response->getSession()->get('errors')->first('reference'))->toContain('Too many tries');
});

it('slows a scan down the booking codes from one address, even though every code it tries is valid', function () {
    ['property' => $property, 'unit' => $unit] = conciergeSetup();

    foreach (range(1, 10) as $ignored) {
        conciergeFind($property->code, 'R100')->assertSessionHasNoErrors();
    }

    $this->travelTo(now()->addSeconds(61));

    foreach (range(1, 5) as $ignored) {
        conciergeFind($property->code, 'R100')->assertSessionHasNoErrors();
    }

    $tokens = BookingPreCheckInToken::query()->where('reservation_unit_id', $unit->id)->count();
    $response = conciergeFind($property->code, 'R100')->assertSessionHasErrors('reference');

    expect($response->getSession()->get('errors')->first('reference'))->toContain('Too many tries')
        ->and(BookingPreCheckInToken::query()->where('reservation_unit_id', $unit->id)->count())->toBe($tokens);
});

it('gives the attempt back when the stay is found, so a guest who typed it right is never locked', function () {
    ['property' => $property] = conciergeSetup();

    foreach (range(1, 10) as $ignored) {
        conciergeFind($property->code, 'R100')->assertSessionHasNoErrors();
    }
});

it('does not open a stay from the staff reference, only from the booking code', function () {
    ['property' => $property, 'unit' => $unit] = conciergeSetup();

    conciergeFind($property->code, $unit->reservation->reference)->assertSessionHasErrors('reference');
});

it('says why a booking code opens nothing when the booking exists at this property', function (array $unit, string $message) {
    ['property' => $property] = conciergeSetup($unit);

    $response = conciergeFind($property->code, 'R100')->assertSessionHasErrors('reference');

    expect($response->getSession()->get('errors')->first('reference'))->toContain($message);
})->with([
    'checked out' => [['status' => UnitStatus::CheckedOut, 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14', 'checked_in_at' => '2026-10-12 15:00:00', 'checked_out_at' => '2026-10-14 10:00:00'], 'already checked out'],
    'cancelled' => [['status' => UnitStatus::Cancelled], 'no longer active'],
]);

it('says the booking has more than one room', function () {
    ['property' => $property, 'unit' => $unit] = conciergeSetup();
    ReservationUnit::factory()->create(['reservation_id' => $unit->reservation_id, 'room_type_id' => $unit->room_type_id, 'arrival_date' => '2026-10-14', 'departure_date' => '2026-10-16']);

    $response = conciergeFind($property->code, 'R100')->assertSessionHasErrors('reference');

    expect($response->getSession()->get('errors')->first('reference'))->toContain('more than one room');
});

it('sends a checked-in guest from the Check out tile to the bill', function () {
    ['property' => $property] = conciergeSetup(['status' => UnitStatus::CheckedIn, 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14', 'checked_in_at' => now()->subDays(2)]);

    $response = $this->post("/stay/{$property->code}", ['reference' => 'R100', 'for' => 'check-out'])->assertRedirect();

    expect($response->headers->get('Location'))->toEndWith('/bill');
});

it('keeps a guest who has not checked in on the Check out page, with a way to check in', function () {
    ['property' => $property] = conciergeSetup();

    $this->post("/stay/{$property->code}", ['reference' => 'R100', 'for' => 'check-out'])
        ->assertRedirect("/stay/{$property->code}/find?for=check-out")
        ->assertSessionHas('checkInHref', fn (string $href) => str_starts_with($href, '/c/'));

    $this->get("/stay/{$property->code}/find?for=check-out")->assertInertia(fn (Assert $page) => $page
        ->component('concierge/find')
        ->where('checkOut', true)
        ->where('checkInHref', fn (string $href) => str_starts_with($href, '/c/')));
});

it('opens an arriving stay by the name on the booking and starts at the ID step', function (string $name) {
    ['property' => $property] = conciergeNameSetup();

    $response = conciergeFind($property->code, $name)->assertSessionHasNoErrors()->assertRedirect();

    conciergeStep(basename($response->headers->get('Location')), 'identity');
})->with([['Aisha Rahman'], [' rahman, AISHA ']]);

it('opens nothing by a name that is not enough', function (array $unit, string $name) {
    ['property' => $property] = conciergeNameSetup($unit);

    conciergeFind($property->code, $name)->assertSessionHasErrors('reference');
})->with([
    'one word' => [[], 'Aisha'],
    'another name' => [[], 'Aisha Karim'],
    'not arrived yet' => [['arrival_date' => '2026-10-20', 'departure_date' => '2026-10-22'], 'Aisha Rahman'],
]);

it('asks a returning guest with a saved ID to confirm their ID number before opening the stay by name', function () {
    ['property' => $property, 'guest' => $guest, 'unit' => $unit] = conciergeNameSetup();
    BookingPreCheckInToken::query()->delete();
    $guest->documents()->create(['type' => 'mykad', 'number' => '900101141234', 'issuing_country_code' => 'MY']);

    // Name alone asks for the saved ID number and issues no stay link yet.
    conciergeFind($property->code, 'Aisha Rahman')
        ->assertRedirect("/stay/{$property->code}/find")
        ->assertSessionHas('confirmId', true)
        ->assertSessionHas('confirmName', 'Aisha Rahman');
    expect(BookingPreCheckInToken::query()->where('reservation_unit_id', $unit->id)->count())->toBe(0);

    $this->get("/stay/{$property->code}/find")->assertInertia(fn (Assert $page) => $page
        ->component('concierge/find')
        ->where('confirmId', true)
        ->where('confirmName', 'Aisha Rahman'));

    // A wrong ID number is refused and still issues no stay link.
    $this->post("/stay/{$property->code}", ['reference' => 'Aisha Rahman', 'id_number' => '900101-14-9999'])
        ->assertRedirect("/stay/{$property->code}/find")
        ->assertSessionHasErrors('id_number');
    expect(BookingPreCheckInToken::query()->where('reservation_unit_id', $unit->id)->count())->toBe(0);

    // The matching ID number (ignoring dashes and spaces) opens the stay.
    $response = $this->post("/stay/{$property->code}", ['reference' => 'Aisha Rahman', 'id_number' => ' 900101-14-1234 '])
        ->assertSessionHasNoErrors()
        ->assertRedirect();
    $token = BookingPreCheckInToken::query()->where('reservation_unit_id', $unit->id)->sole();
    expect(hash('sha256', basename($response->headers->get('Location'))))->toBe($token->token_hash);
});

it('still opens a returning guest stay directly with the booking code without asking for an ID number', function () {
    ['property' => $property, 'guest' => $guest] = conciergeNameSetup();
    conciergeIdentityDone($guest);

    conciergeFind($property->code, 'R100')->assertSessionHasNoErrors()->assertRedirect();
});

it('opens nothing when two arriving stays share the name', function () {
    ['property' => $property] = conciergeNameSetup();
    kioskStayFor($property, 'R-200');

    conciergeFind($property->code, 'Aisha Rahman')->assertSessionHasErrors('reference');
});

it('opens nothing by name while Check in by name is off', function () {
    ['property' => $property] = conciergeSetup();

    conciergeFind($property->code, 'Aisha Rahman')->assertSessionHasErrors('reference');
});

it('answers a checked-in name the same as an unknown one', function () {
    ['property' => $property] = conciergeNameSetup(['status' => UnitStatus::CheckedIn, 'checked_in_at' => '2026-10-14 09:00:00']);
    foreach (['Aisha Rahman', 'Nobody Here'] as $name) {
        $this->flushSession();
        $response = conciergeFind($property->code, $name)->assertSessionHasErrors('reference');

        expect($response->getSession()->get('errors')->first('reference'))->toBe('We could not open a booking with that code or name. Already checked in? Use your booking code, or ask the front desk.');
    }
});

it('still requires a booking code on the Check out page even while Check in by name is on', function () {
    ['property' => $property] = conciergeNameSetup();

    $response = $this->post("/stay/{$property->code}", ['reference' => 'Aisha Rahman', 'for' => 'check-out'])
        ->assertSessionHasErrors('reference');

    expect($response->getSession()->get('errors')->first('reference'))->toContain('with that code');
});

it('locks name variants under one lockout bucket across addresses', function () {
    ['property' => $property] = conciergeNameSetup();

    foreach (['Nobody Here', 'nobody  here', 'Here, Nobody', 'Nobody bin Here', 'HERE NOBODY'] as $index => $variant) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.'.($index + 1)])
            ->post("/stay/{$property->code}", ['reference' => $variant]);
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.99'])
        ->post("/stay/{$property->code}", ['reference' => 'Nobody Here'])
        ->assertSessionHasErrors('reference');

    expect($response->getSession()->get('errors')->first('reference'))->toContain('Too many tries');
});
