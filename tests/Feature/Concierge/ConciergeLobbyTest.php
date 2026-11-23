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
