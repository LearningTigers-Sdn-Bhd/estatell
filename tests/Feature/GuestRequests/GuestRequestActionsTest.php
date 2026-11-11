<?php

use App\Actions\GuestRequests\AssignGuestRequest;
use App\Actions\GuestRequests\CancelGuestRequest;
use App\Actions\GuestRequests\GuestRequestResult;
use App\Actions\GuestRequests\OpenGuestRequest;
use App\Actions\GuestRequests\ReopenGuestRequest;
use App\Actions\GuestRequests\ResolveGuestRequest;
use App\Actions\GuestRequests\StartGuestRequest;
use App\Enums\GuestRequestRefusal;
use App\Enums\GuestRequestSource;
use App\Enums\GuestRequestStatus;
use App\Enums\GuestRequestType;
use App\Enums\UnitStatus;
use App\Models\GuestRequest;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\QueryException;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/GuestRequestSetup.php';

function openRequest(array $setup, GuestRequestType $type = GuestRequestType::Housekeeping, ?ReservationUnit $stay = null, ?int $roomId = null, ?User $actor = null, bool $noStay = false): GuestRequestResult
{
    return app(OpenGuestRequest::class)($actor, $setup['property'], $type, GuestRequestSource::InPerson, ' Two extra towels ', $noStay ? null : ($stay ?? $setup['unit']), $roomId);
}

it('opens a request for a checked-in stay, in the room it sleeps in, on the business date', function () {
    $setup = guestRequestSetup();

    $result = openRequest($setup, actor: $setup['owner']);

    $request = $result->request;
    expect($request->room_id)->toBe($setup['room']->id)
        ->and($request->reservation_unit_id)->toBe($setup['unit']->id)
        ->and($request->status)->toBe(GuestRequestStatus::Open)
        ->and($request->details)->toBe('Two extra towels')
        ->and($request->opened_business_date->toDateString())->toBe('2026-10-14')
        ->and($request->opened_by)->toBe($setup['owner']->id);
    $log = Activity::query()->where('event', 'guest_request.opened')->sole();
    expect($log->causer_id)->toBe($setup['owner']->id)
        ->and($log->properties->all())->toBe(['property_id' => $setup['property']->id, 'type' => 'housekeeping', 'source' => 'in_person']);
});

it('has no opener when the guest\'s own page opens the request', function () {
    $setup = guestRequestSetup();

    $result = app(OpenGuestRequest::class)(null, $setup['property'], GuestRequestType::Maintenance, GuestRequestSource::Concierge, 'Shower drain blocked', $setup['unit'], null);

    expect($result->request->opened_by)->toBeNull()->and($result->request->source)->toBe(GuestRequestSource::Concierge);
});

it('refuses housekeeping and maintenance for a guest who is not checked in, and lets a complaint through after check-out', function () {
    $setup = guestRequestSetup();
    $setup['unit']->forceFill(['status' => UnitStatus::CheckedOut, 'checked_out_at' => now()])->save();

    expect(openRequest($setup, GuestRequestType::Housekeeping)->refusal)->toBe(GuestRequestRefusal::StayNotInHouse)
        ->and(openRequest($setup, GuestRequestType::Maintenance)->refusal)->toBe(GuestRequestRefusal::StayNotInHouse)
        ->and(openRequest($setup, GuestRequestType::Complaint)->isRefused())->toBeFalse()
        ->and(openRequest($setup, GuestRequestType::Other)->isRefused())->toBeFalse();

    $setup['unit']->forceFill(['status' => UnitStatus::Confirmed, 'checked_in_at' => null, 'checked_out_at' => null])->save();
    expect(openRequest($setup, GuestRequestType::Complaint)->refusal)->toBe(GuestRequestRefusal::StayNotInHouse)
        ->and(GuestRequest::query()->count())->toBe(2);
});

it('refuses a room the stay does not use, a missing target and a stay of another property', function () {
    $setup = guestRequestSetup();
    $other = Room::factory()->create(['property_id' => $setup['property']->id]);
    $foreign = guestRequestSetup();

    expect(openRequest($setup, roomId: $other->id)->refusal)->toBe(GuestRequestRefusal::RoomMismatch)
        ->and(openRequest($setup, noStay: true)->refusal)->toBe(GuestRequestRefusal::NoTarget)
        ->and(openRequest($setup, stay: $foreign['unit'])->refusal)->toBe(GuestRequestRefusal::NoTarget)
        ->and(GuestRequest::query()->count())->toBe(0);
});

it('opens a room-only request, and refuses another property\'s room', function () {
    $setup = guestRequestSetup();
    $foreignRoom = Room::factory()->create(['property_id' => Property::factory()->create()->id]);

    $result = openRequest($setup, GuestRequestType::Complaint, roomId: $setup['room']->id, noStay: true);

    expect($result->request->reservation_unit_id)->toBeNull()->and($result->request->room_id)->toBe($setup['room']->id)
        ->and(openRequest($setup, GuestRequestType::Complaint, roomId: $foreignRoom->id, noStay: true)->refusal)->toBe(GuestRequestRefusal::RoomMismatch);
});

it('starts, resolves and logs each step once, and a repeat click is not an error', function () {
    $setup = guestRequestSetup();
    $request = GuestRequest::factory()->create(['property_id' => $setup['property']->id]);

    app(StartGuestRequest::class)($setup['owner'], $request);
    app(StartGuestRequest::class)($setup['owner'], $request);
    $resolved = app(ResolveGuestRequest::class)($setup['owner'], $request, ' Towels delivered ');
    app(ResolveGuestRequest::class)($setup['owner'], $request, 'again');

    $request->refresh();
    expect($resolved->isRefused())->toBeFalse()
        ->and($request->status)->toBe(GuestRequestStatus::Resolved)
        ->and($request->resolved_by)->toBe($setup['owner']->id)
        ->and($request->resolved_business_date->toDateString())->toBe('2026-10-14')
        ->and($request->resolution_note)->toBe('Towels delivered')
        ->and(Activity::query()->where('event', 'guest_request.started')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'guest_request.resolved')->count())->toBe(1);
});

it('refuses a move the status table does not allow', function () {
    $setup = guestRequestSetup();
    $resolved = GuestRequest::factory()->resolved()->create(['property_id' => $setup['property']->id]);
    $cancelled = GuestRequest::factory()->cancelled()->create(['property_id' => $setup['property']->id]);

    expect(app(StartGuestRequest::class)($setup['owner'], $resolved)->refusal)->toBe(GuestRequestRefusal::InvalidTransition)
        ->and(app(ResolveGuestRequest::class)($setup['owner'], $cancelled, null)->refusal)->toBe(GuestRequestRefusal::InvalidTransition)
        ->and(app(CancelGuestRequest::class)($setup['owner'], $resolved, 'No')->refusal)->toBe(GuestRequestRefusal::InvalidTransition)
        ->and($resolved->refresh()->status)->toBe(GuestRequestStatus::Resolved);
});

it('needs a note to cancel, and keeps the resolved fields empty', function () {
    $setup = guestRequestSetup();
    $request = GuestRequest::factory()->create(['property_id' => $setup['property']->id]);

    expect(app(CancelGuestRequest::class)($setup['owner'], $request, '  ')->refusal)->toBe(GuestRequestRefusal::NoteRequired)
        ->and($request->refresh()->status)->toBe(GuestRequestStatus::Open);

    app(CancelGuestRequest::class)($setup['owner'], $request, 'Duplicate');

    expect($request->refresh()->status)->toBe(GuestRequestStatus::Cancelled)
        ->and($request->resolved_at)->toBeNull()
        ->and($request->resolution_note)->toBe('Duplicate');
});

it('reopens a request resolved on the open business date, clearing the resolution', function () {
    $setup = guestRequestSetup();
    $request = GuestRequest::factory()->resolved('2026-10-14')->create(['property_id' => $setup['property']->id, 'resolved_by' => $setup['owner']->id, 'resolution_note' => 'Done']);

    $result = app(ReopenGuestRequest::class)($setup['owner'], $request);

    $request->refresh();
    expect($result->isRefused())->toBeFalse()
        ->and($request->status)->toBe(GuestRequestStatus::Open)
        ->and($request->resolved_at)->toBeNull()
        ->and($request->resolved_business_date)->toBeNull()
        ->and($request->resolved_by)->toBeNull()
        ->and($request->resolution_note)->toBeNull();
});

it('does not reopen a request resolved on a closed business date, and always reopens a cancelled one', function () {
    $setup = guestRequestSetup();
    $old = GuestRequest::factory()->resolved('2026-10-13')->create(['property_id' => $setup['property']->id]);
    $cancelled = GuestRequest::factory()->cancelled()->create(['property_id' => $setup['property']->id]);

    expect(app(ReopenGuestRequest::class)($setup['owner'], $old)->refusal)->toBe(GuestRequestRefusal::ResolvedOnClosedDate)
        ->and($old->refresh()->status)->toBe(GuestRequestStatus::Resolved)
        ->and(app(ReopenGuestRequest::class)($setup['owner'], $cancelled)->isRefused())->toBeFalse()
        ->and($cancelled->refresh()->status)->toBe(GuestRequestStatus::Open);
});

it('assigns a member of the property and takes the request back, refusing a person of another property', function () {
    $setup = guestRequestSetup();
    $request = GuestRequest::factory()->create(['property_id' => $setup['property']->id]);
    $outsider = User::factory()->owner()->create();

    app(AssignGuestRequest::class)($setup['owner'], $request, $setup['owner']);
    expect($request->refresh()->assigned_to)->toBe($setup['owner']->id);

    expect(app(AssignGuestRequest::class)($setup['owner'], $request, $outsider)->refusal)->toBe(GuestRequestRefusal::AssigneeNotAllowed)
        ->and($request->refresh()->assigned_to)->toBe($setup['owner']->id);

    app(AssignGuestRequest::class)($setup['owner'], $request, null);
    expect($request->refresh()->assigned_to)->toBeNull();
});

it('keeps each status table and constraint honest', function () {
    foreach (GuestRequestStatus::cases() as $from) {
        foreach (GuestRequestStatus::cases() as $to) {
            $allowed = match ($from) {
                GuestRequestStatus::Open => in_array($to, [GuestRequestStatus::InProgress, GuestRequestStatus::Resolved, GuestRequestStatus::Cancelled], true),
                GuestRequestStatus::InProgress => in_array($to, [GuestRequestStatus::Open, GuestRequestStatus::Resolved, GuestRequestStatus::Cancelled], true),
                default => $to === GuestRequestStatus::Open,
            };

            expect($from->canTransitionTo($to))->toBe($allowed);
        }
    }

    $setup = guestRequestSetup();

    expect(fn () => GuestRequest::factory()->create(['property_id' => $setup['property']->id, 'status' => GuestRequestStatus::Resolved]))->toThrow(QueryException::class);
});
