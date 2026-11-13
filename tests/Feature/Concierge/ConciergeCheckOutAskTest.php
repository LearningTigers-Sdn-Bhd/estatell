<?php

use App\Actions\Concierge\RequestConciergeCheckOut;
use App\Enums\GuestRequestSource;
use App\Enums\GuestRequestStatus;
use App\Enums\GuestRequestType;
use App\Enums\UnitStatus;
use App\Models\FolioLine;
use App\Models\GuestRequest;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/ConciergeSetup.php';

it('lets a guest who cannot check out alone ask the front desk, with a note, in the staff requests list', function () {
    ['token' => $token, 'unit' => $unit, 'folio' => $folio, 'room' => $room] = conciergeInStaySetup();
    FolioLine::factory()->for($folio)->create(['amount' => '20.00', 'unit_amount' => '20.00']);

    $this->post("/c/{$token}/check-out/ask", ['note' => '  We leave at 10 am and need a taxi  '])->assertRedirect("/c/{$token}/bill");

    $request = GuestRequest::query()->sole();
    expect($request->type)->toBe(GuestRequestType::Other)
        ->and($request->source)->toBe(GuestRequestSource::Concierge)
        ->and($request->status)->toBe(GuestRequestStatus::Open)
        ->and($request->details)->toBe('Check-out request: We leave at 10 am and need a taxi')
        ->and($request->opened_by)->toBeNull()
        ->and($request->reservation_unit_id)->toBe($unit->id)
        ->and($request->room_id)->toBe($room->id)
        ->and($unit->refresh()->status)->toBe(UnitStatus::CheckedIn);
});

it('takes a request with no note, and only one at a time', function () {
    ['token' => $token] = conciergeInStaySetup();

    $this->post("/c/{$token}/check-out/ask")->assertRedirect("/c/{$token}/bill");
    $this->post("/c/{$token}/check-out/ask", ['note' => 'Again'])->assertRedirect("/c/{$token}/bill");

    expect(GuestRequest::query()->sole()->details)->toBe(RequestConciergeCheckOut::PREFIX);
});

it('shows "check-out asked" on the bill and the stay page while the request is open, and asks again once it is answered', function () {
    ['token' => $token, 'folio' => $folio] = conciergeInStaySetup();
    FolioLine::factory()->for($folio)->create(['amount' => '20.00', 'unit_amount' => '20.00']);

    $this->get("/c/{$token}/bill")->assertInertia(fn (Assert $page) => $page->where('bill.checkOutAsked', false)->where('bill.canCheckOut', false));
    $this->get("/c/{$token}")->assertInertia(fn (Assert $page) => $page->where('stay.checkOutAsked', false));

    $this->post("/c/{$token}/check-out/ask");

    $this->get("/c/{$token}/bill")->assertInertia(fn (Assert $page) => $page->where('bill.checkOutAsked', true));
    $this->get("/c/{$token}")->assertInertia(fn (Assert $page) => $page->where('stay.checkOutAsked', true));

    GuestRequest::query()->sole()->forceFill(['status' => GuestRequestStatus::Resolved, 'resolved_at' => now(), 'resolved_business_date' => '2026-10-14'])->save();
    $this->get("/c/{$token}/bill")->assertInertia(fn (Assert $page) => $page->where('bill.checkOutAsked', false));
});

it('does not take a check-out request for a stay that is not checked in', function () {
    ['token' => $token, 'unit' => $unit] = conciergeInStaySetup();
    $unit->forceFill(['status' => UnitStatus::Confirmed, 'checked_in_at' => null])->save();

    $this->post("/c/{$token}/check-out/ask", ['note' => 'x'])->assertRedirect("/c/{$token}");

    expect(GuestRequest::query()->count())->toBe(0);
});

it('shows a checked-out guest how their refund request is going', function () {
    ['token' => $token, 'property' => $property] = conciergeInStaySetup();
    $property->settings->forceFill(['concierge_refund_requests_enabled' => true])->save();
    $this->post("/c/{$token}/check-out");

    conciergeStep($token, 'checked_out')->assertInertia(fn (Assert $page) => $page->where('stay.refundRequest', null)->where('stay.canRequestRefund', true));

    $this->post("/c/{$token}/refund", ['details' => 'Charged twice']);

    $this->get("/c/{$token}")->assertInertia(fn (Assert $page) => $page
        ->where('stay.canRequestRefund', false)
        ->where('stay.refundRequest.details', 'Charged twice')
        ->where('stay.refundRequest.status.value', 'open')
        ->where('stay.refundRequest.note', null));

    GuestRequest::query()->sole()->forceFill(['status' => GuestRequestStatus::Resolved, 'resolved_at' => now(), 'resolved_business_date' => '2026-10-14', 'resolution_note' => 'Refunded to your card'])->save();

    $this->get("/c/{$token}")->assertInertia(fn (Assert $page) => $page
        ->where('stay.refundRequest.status.value', 'resolved')
        ->where('stay.refundRequest.note', 'Refunded to your card'));
});
