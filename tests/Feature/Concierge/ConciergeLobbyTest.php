<?php

use App\Features\Concierge;
use App\Models\BookingPreCheckInToken;
use App\Models\Property;
use App\Models\ReservationUnit;
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
})->with([['R-100'], ['r-100'], [' R-100 ']]);

it('gives the same answer for any code that opens nothing, and issues no link', function () {
    ['property' => $property] = conciergeSetup();
    $before = BookingPreCheckInToken::query()->count();

    $response = conciergeFind($property->code, 'R-999')->assertSessionHasErrors('reference');

    expect($response->getSession()->get('errors')->first('reference'))->toContain('could not find')
        ->and(BookingPreCheckInToken::query()->count())->toBe($before);
});

it('does not open a stay of another property', function () {
    ['property' => $property] = conciergeSetup();
    kioskStayFor(Property::factory()->create(), 'R-200');

    conciergeFind($property->code, 'R-200')->assertSessionHasErrors('reference');
});

it('opens nothing when the code matches more than one room', function () {
    ['property' => $property, 'unit' => $unit] = conciergeSetup();
    $second = ReservationUnit::factory()->create(['reservation_id' => $unit->reservation_id, 'room_type_id' => $unit->room_type_id, 'arrival_date' => '2026-10-14', 'departure_date' => '2026-10-16']);
    $unit->guests()->first()->replicate(['id'])->forceFill(['reservation_unit_id' => $second->id])->save();

    conciergeFind($property->code, 'R-100')->assertSessionHasErrors('reference');
});

it('locks a booking code after repeated wrong tries from the same address, even for the right code', function () {
    ['property' => $property] = conciergeSetup();

    foreach (range(1, 8) as $number) {
        conciergeFind($property->code, "R-9{$number}");
    }

    $response = conciergeFind($property->code, 'R-100')->assertSessionHasErrors('reference');
    expect($response->getSession()->get('errors')->first('reference'))->toContain('Too many tries');
});

it('locks one booking code against guesses spread over many addresses', function () {
    ['property' => $property] = conciergeSetup();

    foreach (range(1, 5) as $number) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$number}"])->post("/stay/{$property->code}", ['reference' => 'R-100x']);
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])->post("/stay/{$property->code}", ['reference' => 'R-100x'])->assertSessionHasErrors('reference');
    expect($response->getSession()->get('errors')->first('reference'))->toContain('Too many tries');
});

it('slows a scan down the booking codes from one address, even though every code it tries is valid', function () {
    ['property' => $property, 'unit' => $unit] = conciergeSetup();

    foreach (range(1, 10) as $ignored) {
        conciergeFind($property->code, 'R-100')->assertSessionHasNoErrors();
    }

    $this->travelTo(now()->addSeconds(61));

    foreach (range(1, 5) as $ignored) {
        conciergeFind($property->code, 'R-100')->assertSessionHasNoErrors();
    }

    $tokens = BookingPreCheckInToken::query()->where('reservation_unit_id', $unit->id)->count();
    $response = conciergeFind($property->code, 'R-100')->assertSessionHasErrors('reference');

    expect($response->getSession()->get('errors')->first('reference'))->toContain('Too many tries')
        ->and(BookingPreCheckInToken::query()->where('reservation_unit_id', $unit->id)->count())->toBe($tokens);
});

it('gives the attempt back when the stay is found, so a guest who typed it right is never locked', function () {
    ['property' => $property] = conciergeSetup();

    foreach (range(1, 10) as $ignored) {
        conciergeFind($property->code, 'R-100')->assertSessionHasNoErrors();
    }
});
