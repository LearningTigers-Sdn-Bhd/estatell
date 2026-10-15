<?php

use App\Models\Guest;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;

beforeEach(function () {
    $this->property = Property::factory()->create(['code' => 'ES10142']);
    $this->owner = User::factory()->owner()->create(['property_id' => $this->property->id]);
    $this->admin = User::factory()->admin()->create();
});

test('staff finds their guests, rooms and reservations and nothing from another property', function () {
    $guest = Guest::factory()->create(['property_id' => $this->property->id, 'full_name' => 'Ahmad Faiz']);
    Guest::factory()->create(['full_name' => 'Ahmad Elsewhere']);
    Room::factory()->create(['property_id' => $this->property->id, 'number' => '204']);
    $reservation = Reservation::factory()->create(['property_id' => $this->property->id, 'booker_guest_id' => $guest->id]);
    Reservation::factory()->create();

    $response = $this->actingAs($this->owner)->getJson(route('command-search', ['q' => 'ah']));

    $response->assertOk();
    $results = collect($response->json('results'));

    expect($results->pluck('group')->all())->toContain('Guests', 'Reservations')
        ->and($results->where('group', 'Guests')->pluck('label')->all())->toContain('Ahmad Faiz')
        ->and($results->where('group', 'Guests')->pluck('label')->all())->not->toContain('Ahmad Elsewhere')
        ->and($results->where('group', 'Reservations')->pluck('label')->all())->toContain($reservation->reference);

    $rooms = $this->actingAs($this->owner)->getJson(route('command-search', ['q' => '20']))->assertOk()->json('results');

    expect(collect($rooms)->where('group', 'Rooms')->pluck('label')->all())->toContain('Room 204');

    $refs = $this->actingAs($this->owner)->getJson(route('command-search', ['q' => mb_substr($reservation->reference, 0, 6)]))->assertOk()->json('results');

    expect(collect($refs)->where('group', 'Reservations')->pluck('label')->all())->toContain($reservation->reference);
});

test('staff results stop at five per group and need the matching permission', function () {
    Guest::factory()->count(7)->sequence(fn ($sequence) => ['full_name' => 'Lim '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)])->create(['property_id' => $this->property->id]);
    $limited = User::factory()->withPermissions(['reservations.view'])->create(['property_id' => $this->property->id]);

    $guests = $this->actingAs($this->owner)->getJson(route('command-search', ['q' => 'li']))->assertOk()->json('results');

    expect(collect($guests)->where('group', 'Guests'))->toHaveCount(5);

    $withoutGuests = $this->actingAs($limited)->getJson(route('command-search', ['q' => 'li']))->assertOk()->json('results');

    expect(collect($withoutGuests)->where('group', 'Guests'))->toHaveCount(0)
        ->and(collect($withoutGuests)->where('group', 'Rooms'))->toHaveCount(0);
});

test('staff of a property still in setup gets no records', function () {
    $property = Property::factory()->onboarding()->create();
    $owner = User::factory()->owner()->create(['property_id' => $property->id]);
    Guest::factory()->create(['property_id' => $property->id, 'full_name' => 'Ahmad Faiz']);

    $this->actingAs($owner)->getJson(route('command-search', ['q' => 'ah']))
        ->assertOk()
        ->assertJsonPath('results', []);
});

test('the query needs at least two characters', function () {
    $this->actingAs($this->owner)->getJson(route('command-search', ['q' => 'a']))->assertUnprocessable();
    $this->actingAs($this->owner)->getJson(route('command-search'))->assertUnprocessable();
});

test('guests are sent to sign in', function () {
    $this->get(route('command-search', ['q' => 'ah']))->assertRedirectToRoute('login');
});

test('an admin finds properties by code, name or city and opens the support page', function () {
    $property = Property::factory()->create(['name' => 'Pulau Inn '.uniqid(), 'city' => 'Langkawi '.uniqid()]);

    $byCode = $this->actingAs($this->admin)->getJson(route('command-search', ['q' => $property->code]))->assertOk()->json('results');
    $byName = $this->actingAs($this->admin)->getJson(route('command-search', ['q' => 'pulau']))->assertOk()->json('results');

    $first = collect($byCode)->firstWhere('group', 'Properties');

    expect($first['label'])->toContain($property->code)
        ->and($first['href'])->toBe(route('admin.properties.show', $property->code, absolute: false))
        ->and(collect($byName)->firstWhere('group', 'Properties')['label'])->toContain('Pulau Inn')
        ->and($first['actions'][0]['title'])->toBe('View as owner')
        ->and($first['actions'][0]['href'])->toBe(route('view-as.show', $property->code, absolute: false));
});

test('a partner sees only their own properties', function () {
    $partnerUser = User::factory()->partner()->create();
    $prefix = 'Harbour '.uniqid();
    $mine = Property::factory()->create(['partner_id' => $partnerUser->partner_id, 'name' => $prefix.' Mine']);
    $theirs = Property::factory()->create(['name' => $prefix.' Theirs']);

    $results = $this->actingAs($partnerUser)->getJson(route('command-search', ['q' => 'harbour']))->assertOk()->json('results');
    $labels = collect($results)->where('group', 'Properties')->pluck('label')->all();

    expect($labels)->toContain($mine->code.' · '.$mine->name)
        ->and($labels)->not->toContain($theirs->code.' · '.$theirs->name);
});

test('an admin finds a reservation by its reference through the property prefix', function () {
    $reservation = Reservation::factory()->create(['property_id' => $this->property->id]);
    $other = Reservation::factory()->create();

    $results = $this->actingAs($this->admin)->getJson(route('command-search', ['q' => $reservation->reference]))->assertOk()->json('results');
    $found = collect($results)->firstWhere('label', $reservation->reference);

    expect($found['group'])->toBe('Reservations')
        ->and($found['description'])->toContain($this->property->code)
        ->and($found['description'])->not->toContain($reservation->booker->full_name)
        ->and($found['href'])->toContain('/view-as/'.$this->property->code)
        ->and(collect($results)->pluck('label')->all())->not->toContain($other->reference);
});

test('a property result never starts impersonation directly', function () {
    $results = $this->actingAs($this->admin)->getJson(route('command-search', ['q' => 'ES10']))->assertOk()->json('results');
    $hrefs = collect($results)->where('group', 'Properties')->pluck('href')->all();

    foreach ($hrefs as $href) {
        expect(str_starts_with($href, '/view-as/') || str_starts_with($href, '/admin/properties'))->toBeTrue();
    }

    $actions = collect($results)->firstWhere('group', 'Properties')['actions'] ?? [];

    foreach ($actions as $action) {
        expect($action['href'])->toStartWith('/view-as/');
    }
});
