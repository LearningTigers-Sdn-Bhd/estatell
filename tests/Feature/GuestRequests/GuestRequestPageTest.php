<?php

use App\Enums\GuestRequestStatus;
use App\Enums\GuestRequestType;
use App\Enums\PermissionEnum;
use App\Models\GuestRequest;
use App\Models\Property;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/GuestRequestSetup.php';

it('lists open requests oldest first, with the moves the viewer may make', function () {
    ['owner' => $owner, 'property' => $property] = guestRequestSetup();
    $newer = GuestRequest::factory()->create(['property_id' => $property->id, 'details' => 'Newer', 'opened_at' => now()->subMinutes(5)]);
    $older = GuestRequest::factory()->inProgress()->create(['property_id' => $property->id, 'details' => 'Older', 'opened_at' => now()->subHours(2)]);
    GuestRequest::factory()->resolved()->create(['property_id' => $property->id, 'details' => 'Done']);
    GuestRequest::factory()->create(['property_id' => Property::factory()->create()->id, 'details' => 'Other property']);

    $this->actingAs($owner)->get(route('guest-requests.index', $property))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('guest-requests/index')
        ->has('requests', 2)
        ->where('requests.0.id', $older->id)
        ->where('requests.0.moves', ['resolve', 'cancel'])
        ->where('requests.1.id', $newer->id)
        ->where('requests.1.moves', ['start', 'resolve', 'cancel'])
        ->where('requests.1.status.value', 'open')
        ->where('canManage', true));
});

it('filters by status and type', function () {
    ['owner' => $owner, 'property' => $property] = guestRequestSetup();
    GuestRequest::factory()->resolved()->create(['property_id' => $property->id, 'details' => 'Done']);
    GuestRequest::factory()->cancelled()->create(['property_id' => $property->id, 'details' => 'Gone']);
    GuestRequest::factory()->create(['property_id' => $property->id, 'type' => GuestRequestType::Complaint]);

    $this->actingAs($owner)->get(route('guest-requests.index', [$property, 'status' => 'all']))->assertInertia(fn (Assert $page) => $page->has('requests', 3));
    $this->actingAs($owner)->get(route('guest-requests.index', [$property, 'status' => 'resolved']))->assertInertia(fn (Assert $page) => $page->has('requests', 1)->where('requests.0.moves', ['reopen']));
    $this->actingAs($owner)->get(route('guest-requests.index', [$property, 'status' => 'all', 'type' => 'complaint']))->assertInertia(fn (Assert $page) => $page->has('requests', 1));
});

it('shows a viewer who may only look no moves, and keeps other staff out', function () {
    ['property' => $property] = guestRequestSetup();
    GuestRequest::factory()->create(['property_id' => $property->id]);
    $viewer = User::factory()->for($property)->withPermissions([PermissionEnum::GuestRequestsView])->create();
    $nobody = User::factory()->for($property)->withPermissions([])->create();

    $this->actingAs($viewer)->get(route('guest-requests.index', $property))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('requests.0.moves', [])
        ->where('canManage', false));
    $this->actingAs($nobody)->get(route('guest-requests.index', $property))->assertForbidden();
});

it('records a request for a stay in the house', function () {
    ['owner' => $owner, 'property' => $property, 'unit' => $unit, 'room' => $room] = guestRequestSetup();

    $this->actingAs($owner)->post(route('guest-requests.store', $property), ['type' => 'housekeeping', 'source' => 'phone', 'details' => 'Two extra towels', 'stay_id' => $unit->id])
        ->assertRedirect(route('guest-requests.index', $property));

    $request = GuestRequest::query()->sole();
    expect($request->room_id)->toBe($room->id)->and($request->opened_by)->toBe($owner->id)->and($request->source->value)->toBe('phone');
});

it('refuses a bad new request and never takes the concierge source from staff', function (array $body, string $field) {
    ['owner' => $owner, 'property' => $property, 'unit' => $unit] = guestRequestSetup();
    $foreign = guestRequestSetup();

    $body = array_map(fn ($value) => $value === 'OWN' ? $unit->id : ($value === 'FOREIGN' ? $foreign['unit']->id : $value), $body);

    $this->actingAs($owner)->post(route('guest-requests.store', $property), ['type' => 'housekeeping', 'source' => 'phone', 'details' => 'Towels', 'stay_id' => $unit->id, ...$body])->assertSessionHasErrors($field);

    expect(GuestRequest::query()->count())->toBe(0);
})->with([
    'no details' => [['details' => ''], 'details'],
    'concierge source' => [['source' => 'concierge'], 'source'],
    'no stay or room' => [['stay_id' => null], 'stay_id'],
    'stay of another property' => [['stay_id' => 'FOREIGN'], 'stay_id'],
]);

it('moves a request through start, resolve and reopen', function () {
    ['owner' => $owner, 'property' => $property] = guestRequestSetup();
    $request = GuestRequest::factory()->create(['property_id' => $property->id]);

    $this->actingAs($owner)->post(route('guest-requests.start', [$property, $request]))->assertRedirect();
    expect($request->refresh()->status)->toBe(GuestRequestStatus::InProgress);

    $this->actingAs($owner)->post(route('guest-requests.resolve', [$property, $request]), ['note' => 'Delivered'])->assertRedirect();
    expect($request->refresh()->status)->toBe(GuestRequestStatus::Resolved)->and($request->resolution_note)->toBe('Delivered');

    $this->actingAs($owner)->post(route('guest-requests.reopen', [$property, $request]))->assertRedirect();
    expect($request->refresh()->status)->toBe(GuestRequestStatus::Open);
});

it('cancels only with a note', function () {
    ['owner' => $owner, 'property' => $property] = guestRequestSetup();
    $request = GuestRequest::factory()->create(['property_id' => $property->id]);

    $this->actingAs($owner)->post(route('guest-requests.cancel', [$property, $request]), ['note' => ''])->assertSessionHasErrors('note');
    expect($request->refresh()->status)->toBe(GuestRequestStatus::Open);

    $this->actingAs($owner)->post(route('guest-requests.cancel', [$property, $request]), ['note' => 'Duplicate'])->assertRedirect();
    expect($request->refresh()->status)->toBe(GuestRequestStatus::Cancelled);
});

it('answers a refusal with a toast and no change', function () {
    ['owner' => $owner, 'property' => $property] = guestRequestSetup();
    $request = GuestRequest::factory()->resolved('2026-10-13')->create(['property_id' => $property->id]);

    $this->actingAs($owner)->post(route('guest-requests.reopen', [$property, $request]))->assertRedirect(route('guest-requests.index', $property));

    expect($request->refresh()->status)->toBe(GuestRequestStatus::Resolved);
});

it('assigns a staff member who can resolve requests and refuses anyone else', function () {
    ['owner' => $owner, 'property' => $property] = guestRequestSetup();
    $request = GuestRequest::factory()->create(['property_id' => $property->id]);
    $housekeeper = User::factory()->for($property)->withPermissions([PermissionEnum::GuestRequestsResolve])->create();
    $viewer = User::factory()->for($property)->withPermissions([PermissionEnum::GuestRequestsView])->create();
    $outsider = User::factory()->owner()->create();

    $this->actingAs($owner)->put(route('guest-requests.assign', [$property, $request]), ['user_id' => $housekeeper->id])->assertRedirect();
    expect($request->refresh()->assigned_to)->toBe($housekeeper->id);

    foreach ([$viewer, $outsider] as $person) {
        $this->actingAs($owner)->put(route('guest-requests.assign', [$property, $request]), ['user_id' => $person->id])->assertSessionHasErrors('user_id');
    }

    expect($request->refresh()->assigned_to)->toBe($housekeeper->id);
    $this->actingAs($owner)->put(route('guest-requests.assign', [$property, $request]), ['user_id' => null])->assertRedirect();
    expect($request->refresh()->assigned_to)->toBeNull();
});

it('splits the work by permission: housekeeping starts and resolves, only the desk records and cancels', function () {
    ['property' => $property] = guestRequestSetup();
    $request = GuestRequest::factory()->create(['property_id' => $property->id]);
    $housekeeper = User::factory()->for($property)->withPermissions([PermissionEnum::GuestRequestsView, PermissionEnum::GuestRequestsResolve])->create();

    $this->actingAs($housekeeper)->post(route('guest-requests.start', [$property, $request]))->assertRedirect();
    $this->actingAs($housekeeper)->post(route('guest-requests.cancel', [$property, $request]), ['note' => 'x'])->assertForbidden();
    $this->actingAs($housekeeper)->post(route('guest-requests.store', $property), ['type' => 'other', 'source' => 'phone', 'details' => 'x', 'room_id' => 1])->assertForbidden();

    expect($request->refresh()->status)->toBe(GuestRequestStatus::InProgress);
});

it('treats the request of another property as missing', function () {
    ['owner' => $owner, 'property' => $property] = guestRequestSetup();
    $foreign = GuestRequest::factory()->create(['property_id' => Property::factory()->create()->id]);

    $this->actingAs($owner)->post(route('guest-requests.start', [$property, $foreign]))->assertNotFound();
    $this->actingAs($owner)->post(route('guest-requests.resolve', [$property, $foreign]))->assertNotFound();

    expect($foreign->refresh()->status)->toBe(GuestRequestStatus::Open);
});
