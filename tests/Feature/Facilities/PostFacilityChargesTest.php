<?php

use App\Actions\Facilities\CancelFacilityBooking;
use App\Actions\Facilities\FacilityBookingResult;
use App\Actions\Facilities\PostFacilityBooking;
use App\Actions\Facilities\PostFacilityCharges;
use App\Actions\Facilities\UpdateFacilityBooking;
use App\Actions\NightAudit\Steps\PostFacilityChargesStep;
use App\Data\Facilities\FacilityBookingInputData;
use App\Enums\AuditExceptionType;
use App\Enums\FolioStatus;
use App\Enums\PermissionEnum;
use App\Features\Facilities;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\NightAudit;
use App\Models\NightAuditException;
use App\Models\Property;
use App\Models\Tax;
use App\Models\User;
use App\Services\Folio\ScheduledFolioRows;
use App\Services\NightAudit\AuditReadiness;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/EventSetup.php';

/**
 * The event setup with an event on the business date (14 Oct 2026), its folio, and the Riverside Hall at 500.00 a day
 * with an 8% exclusive tax.
 *
 * @return array{property: Property, owner: User, event: Event, folio: Folio, hall: Facility}
 */
function facilityPostingSetup(): array
{
    ['property' => $property, 'owner' => $owner] = eventSetup();
    [$event, $folio] = makeEvent($property, $owner, ['starts_on' => '2026-10-14', 'ends_on' => '2026-10-15']);
    $hall = Facility::factory()->create(['property_id' => $property->id, 'name' => 'Riverside Hall']);
    $tax = Tax::factory()->for($property)->create(['rate' => '8.00', 'is_inclusive' => false]);
    $tax->applicableTransactionCodes()->attach($hall->chargeItem->transaction_code_id);

    return compact('property', 'owner', 'event', 'folio', 'hall');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function postingBooking(array $setup, array $attributes = []): FacilityBooking
{
    return FacilityBooking::factory()->create([
        'event_id' => $setup['event']->id,
        'property_id' => $setup['property']->id,
        'facility_id' => $setup['hall']->id,
        'folio_id' => $setup['folio']->id,
        'booking_date' => '2026-10-14',
        'unit_amount' => '500.00',
        ...$attributes,
    ]);
}

test('Post now before the booking date posts one charge with its tax on the business date, and a second click posts nothing', function () {
    $setup = facilityPostingSetup();
    $booking = postingBooking($setup, ['booking_date' => '2026-10-15']);

    $this->actingAs($setup['owner'])
        ->post(route('facility-bookings.post', [$setup['property']->code, $booking]))
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Charge posted to the folio.']);

    $line = FolioLine::query()->where('idempotency_key', 'facility:'.$booking->id)->sole();

    expect($line->folio_id)->toBe($setup['folio']->id)
        ->and($line->amount)->toBe('500.00')
        ->and($line->description)->toBe('Riverside Hall · 18:00–23:00 · 15 Oct')
        ->and($line->posting_date->toDateString())->toBe('2026-10-14')
        ->and($line->service_date->toDateString())->toBe('2026-10-14')
        ->and($line->source_type)->toBe(FacilityBooking::class)
        ->and($line->source_id)->toBe($booking->id)
        ->and($line->children()->sole()->amount)->toBe('40.00');

    $this->actingAs($setup['owner'])
        ->post(route('facility-bookings.post', [$setup['property']->code, $booking]))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This booking is already on the folio.']);

    expect(FolioLine::query()->count())->toBe(2);
});

test('Post now refuses a Tentative booking and one charged by hand, and writes nothing', function () {
    $setup = facilityPostingSetup();
    $tentative = postingBooking($setup, ['status' => 'tentative']);
    $byHand = postingBooking($setup, ['unit_amount' => null, 'starts_at' => '08:00', 'ends_at' => '10:00']);

    expect(app(PostFacilityBooking::class)($setup['owner'], $tentative)->refusal)->toBe(FacilityBookingResult::REFUSAL_TENTATIVE)
        ->and(app(PostFacilityBooking::class)($setup['owner'], $byHand)->refusal)->toBe(FacilityBookingResult::REFUSAL_CHARGED_BY_HAND)
        ->and(FolioLine::query()->count())->toBe(0);
});

test('Post now needs folio.post-charge and the booking of the property in the URL', function () {
    $setup = facilityPostingSetup();
    $booking = postingBooking($setup);
    $booker = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::EventsView, PermissionEnum::FacilitiesBook])->create();
    ['property' => $other, 'owner' => $otherOwner] = eventSetup();

    $this->actingAs($booker)->post(route('facility-bookings.post', [$setup['property']->code, $booking]))->assertForbidden();
    $this->actingAs($otherOwner)->post(route('facility-bookings.post', [$other->code, $booking]))->assertNotFound();

    expect(FolioLine::query()->count())->toBe(0);
});

test('the night audit posts the bookings of the business date once and turns a Tentative one into a Facility exception', function () {
    $setup = facilityPostingSetup();
    $today = postingBooking($setup);
    $tentative = postingBooking($setup, ['status' => 'tentative', 'starts_at' => '08:00', 'ends_at' => '10:00']);
    $tomorrow = postingBooking($setup, ['booking_date' => '2026-10-15']);
    $audit = NightAudit::factory()->create(['business_date_id' => $setup['property']->businessDates()->sole()->id]);
    $date = CarbonImmutable::parse('2026-10-14');

    $outcome = app(PostFacilityChargesStep::class)->run($audit, $date);
    app(PostFacilityChargesStep::class)->run($audit, $date);

    $exception = NightAuditException::query()->sole();

    expect($outcome->processed)->toBe(1)
        ->and($outcome->failed)->toBe(1)
        ->and(FolioLine::query()->where('idempotency_key', 'facility:'.$today->id)->count())->toBe(1)
        ->and(FolioLine::query()->whereIn('idempotency_key', ['facility:'.$tentative->id, 'facility:'.$tomorrow->id])->exists())->toBeFalse()
        ->and(FolioLine::query()->count())->toBe(2)
        ->and($exception->type)->toBe(AuditExceptionType::Facility)
        ->and($exception->facility_booking_id)->toBe($tentative->id)
        ->and($exception->reservation_unit_id)->toBeNull()
        ->and($exception->message)->toBe(FacilityBookingResult::REFUSAL_TENTATIVE);
});

test('the night audit catches up a booking dated before the business date, on the business date', function () {
    $setup = facilityPostingSetup();
    $missed = postingBooking($setup, ['booking_date' => '2026-10-13']);
    $audit = NightAudit::factory()->create(['business_date_id' => $setup['property']->businessDates()->sole()->id]);
    $date = CarbonImmutable::parse('2026-10-14');

    $outcome = app(PostFacilityChargesStep::class)->run($audit, $date);
    app(PostFacilityChargesStep::class)->run($audit, $date);

    $line = FolioLine::query()->where('idempotency_key', 'facility:'.$missed->id)->sole();

    expect($outcome->processed)->toBe(1)
        ->and($line->posting_date->toDateString())->toBe('2026-10-14')
        ->and($line->service_date->toDateString())->toBe('2026-10-13')
        ->and(FolioLine::query()->count())->toBe(2);
});

test('the night audit posts nothing while the Facilities feature is off', function () {
    $setup = facilityPostingSetup();
    postingBooking($setup);
    Feature::for($setup['property'])->deactivate(Facilities::class);

    expect(app(PostFacilityCharges::class)($setup['property'], CarbonImmutable::parse('2026-10-14')))->toBe([])
        ->and(FolioLine::query()->count())->toBe(0);
});

test('a Facility exception shows on the readiness panel and Post now resolves it', function () {
    $setup = facilityPostingSetup();
    $booking = postingBooking($setup, ['status' => 'tentative']);
    $audit = NightAudit::factory()->create(['business_date_id' => $setup['property']->businessDates()->sole()->id]);
    app(PostFacilityChargesStep::class)->run($audit, CarbonImmutable::parse('2026-10-14'));

    $items = collect(app(AuditReadiness::class)->items($setup['property']))->where('facilityBookingId', $booking->id);

    expect($items)->toHaveCount(1)
        ->and($items->first()->eventId)->toBe($setup['event']->id);

    $booking->update(['status' => 'confirmed']);
    app(PostFacilityBooking::class)($setup['owner'], $booking);

    expect(NightAuditException::query()->sole()->resolved_at)->not->toBeNull()
        ->and(collect(app(AuditReadiness::class)->items($setup['property']))->where('facilityBookingId', $booking->id))->toBeEmpty();
});

test('Dismiss resolves a Facility exception and posts nothing', function () {
    $setup = facilityPostingSetup();
    $booking = postingBooking($setup, ['status' => 'tentative']);
    $audit = NightAudit::factory()->create(['business_date_id' => $setup['property']->businessDates()->sole()->id]);
    app(PostFacilityChargesStep::class)->run($audit, CarbonImmutable::parse('2026-10-14'));
    $exception = NightAuditException::query()->sole();

    $this->actingAs($setup['owner'])
        ->postJson(route('night-audit.exceptions.dismiss', [$setup['property']->code, $exception]), ['reason' => 'Charged by hand.'])
        ->assertOk();

    expect($exception->fresh()->resolved_at)->not->toBeNull()
        ->and(collect(app(AuditReadiness::class)->items($setup['property']))->where('facilityBookingId', $booking->id))->toBeEmpty()
        ->and(FolioLine::query()->count())->toBe(0);
});

test('the folio shows a booking as Scheduled until it is posted', function () {
    $setup = facilityPostingSetup();
    $booking = postingBooking($setup);
    $rows = fn (): array => app(ScheduledFolioRows::class)->forFacilityBookings($setup['property']->load('settings'), [$setup['folio']->id]);

    expect($rows())->toHaveCount(1)
        ->and($rows()[0]['rows'])->toHaveCount(2);

    app(PostFacilityBooking::class)($setup['owner'], $booking);

    expect($rows())->toBe([]);
});

test('a posted booking changes only its notes', function () {
    $setup = facilityPostingSetup();
    $booking = postingBooking($setup);
    app(PostFacilityBooking::class)($setup['owner'], $booking);
    $input = fn (array $overrides): FacilityBookingInputData => new FacilityBookingInputData(...[
        'facilityId' => $setup['hall']->id,
        'bookingDate' => '2026-10-14',
        'startsAt' => '18:00',
        'endsAt' => '23:00',
        'quantity' => 1,
        'unitAmount' => '500.00',
        'notes' => null,
        'chargeTo' => null,
        ...$overrides,
    ]);

    $moved = app(UpdateFacilityBooking::class)($setup['owner'], $booking, $input(['startsAt' => '19:00']));
    $noted = app(UpdateFacilityBooking::class)($setup['owner'], $booking, $input(['notes' => 'Stage on the left']));

    expect($moved->refusal)->toBe(FacilityBookingResult::REFUSAL_POSTED)
        ->and($noted->isRefused())->toBeFalse()
        ->and($booking->fresh()->starts_at)->toStartWith('18:00')
        ->and($booking->fresh()->notes)->toBe('Stage on the left');
});

test('cancelling a posted booking reverses its charge and tax, and a closed folio refuses it', function () {
    $setup = facilityPostingSetup();
    $booking = postingBooking($setup);
    $closedBooking = postingBooking($setup, ['starts_at' => '08:00', 'ends_at' => '10:00']);
    app(PostFacilityBooking::class)($setup['owner'], $booking);
    app(PostFacilityBooking::class)($setup['owner'], $closedBooking);

    $result = app(CancelFacilityBooking::class)($setup['owner'], $booking);
    $line = FolioLine::query()->where('idempotency_key', 'facility:'.$booking->id)->sole();

    expect($result->isRefused())->toBeFalse()
        ->and(FolioLine::query()->where('idempotency_key', 'reverse:'.$line->id)->sole()->amount)->toBe('-500.00')
        ->and(FolioLine::query()->whereIn('reversal_of_id', [$line->id, ...$line->children()->pluck('id')])->count())->toBe(2);

    $setup['folio']->update(['status' => FolioStatus::Closed]);

    expect(app(CancelFacilityBooking::class)($setup['owner'], $closedBooking)->refusal)->toBe('folio_not_open')
        ->and($closedBooking->fresh()->status->value)->toBe('confirmed');
});
