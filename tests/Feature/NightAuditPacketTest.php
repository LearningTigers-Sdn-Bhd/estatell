<?php

use App\Enums\AuditStep;
use App\Enums\AuditStepStatus;
use App\Enums\DefaultRoleEnum;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Enums\SystemTransactionCode;
use App\Models\ArPayment;
use App\Models\BusinessDate;
use App\Models\DailyRevenue;
use App\Models\DailyStatistic;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\MealPeriod;
use App\Models\NightAudit;
use App\Models\NightAuditException;
use App\Models\NightAuditStep;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\RatePlan;
use App\Models\RatePlanInclusion;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Support\Facades\View;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 13)->setTime(12, 0));
});

/**
 * A live property whose business date 2026-10-12 is closed with a Completed audit, and an open business date after it.
 *
 * @return array{0: User, 1: Property, 2: NightAudit}
 */
function packetSetup(DefaultRoleEnum $role = DefaultRoleEnum::Manager): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    $closed = BusinessDate::factory()->closed()->create(['property_id' => $property->id, 'date' => '2026-10-12', 'closed_by' => $user->id]);
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-13']);
    $audit = NightAudit::factory()->completed()->create(['business_date_id' => $closed->id, 'run_by' => $user->id]);

    return [$user, $property, $audit];
}

function packetUrl(Property $property, string $date = '2026-10-12'): string
{
    return "/p/{$property->code}/reports/night-audit-history/{$date}/packet";
}

/**
 * Captures the data of the packet view and renders it to HTML.
 *
 * @return object{data: array<string, mixed>}
 */
function capturePacketView(): object
{
    $captured = new stdClass;
    $captured->data = [];
    View::composer('pdf.night-audit-packet', function ($view) use ($captured): void {
        $captured->data = $view->getData();
    });

    return $captured;
}

function packetHtml(object $captured): string
{
    return view('pdf.night-audit-packet', $captured->data)->render();
}

test('a Completed audit of a Closed date streams as an inline PDF', function () {
    [$user, $property] = packetSetup();

    $response = $this->actingAs($user)->get(packetUrl($property));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline')->toContain('night-audit-2026-10-12.pdf');
});

test('the open date, a Failed audit, a missing audit, a bad date and another property return 404', function () {
    [$user, $property] = packetSetup();
    [, $other, $otherAudit] = packetSetup();
    $failedDate = BusinessDate::factory()->closed()->create(['property_id' => $property->id, 'date' => '2026-10-11']);
    NightAudit::factory()->failed()->create(['business_date_id' => $failedDate->id]);

    $this->actingAs($user)->get(packetUrl($property, '2026-10-13'))->assertNotFound();
    $this->actingAs($user)->get(packetUrl($property, '2026-10-11'))->assertNotFound();
    $this->actingAs($user)->get(packetUrl($property, '2026-10-01'))->assertNotFound();
    $this->actingAs($user)->get(packetUrl($property, '2026-13-45'))->assertNotFound();
    $this->actingAs($user)->get(packetUrl($other))->assertNotFound();
    expect($otherAudit->exists)->toBeTrue();
});

test('a user without reports.view is refused', function () {
    [$front, $property] = packetSetup(DefaultRoleEnum::FrontDesk);
    [$finance, $financeProperty] = packetSetup(DefaultRoleEnum::Finance);

    $this->actingAs($front)->get(packetUrl($property))->assertForbidden();
    $this->actingAs($finance)->get(packetUrl($financeProperty))->assertOk();
});

test('the packet holds the statistics, revenue, payments, meals, every exception and the steps', function () {
    [$user, $property, $audit] = packetSetup();
    DailyStatistic::factory()->create(['property_id' => $property->id, 'business_date' => '2026-10-12', 'rooms_available' => 10, 'rooms_sold' => 6, 'beds_available' => 8, 'beds_sold' => 3, 'occupancy' => '50.00', 'adr' => '180.00', 'revpar' => '90.00', 'arrivals' => 3, 'departures' => 2, 'no_shows' => 1, 'in_house_guests' => 9]);
    $room = TransactionCode::factory()->for($property)->system(SystemTransactionCode::RoomRevenue)->create(['code' => 'ROOM', 'name' => 'Room revenue']);
    DailyRevenue::factory()->create(['property_id' => $property->id, 'business_date' => '2026-10-12', 'transaction_code_id' => $room->id, 'amount' => '900.00', 'line_count' => 5]);
    FolioLine::factory()->create(['folio_id' => Folio::factory()->create(['property_id' => $property->id])->id, 'transaction_code_id' => $room->id, 'amount' => '900.00', 'unit_amount' => '900.00', 'posting_date' => '2026-10-12']);

    $folio = Folio::factory()->create(['property_id' => $property->id]);
    $cash = PaymentMethod::factory()->create(['property_id' => $property->id, 'name' => 'Cash']);
    $paid = fn (string $amount, ?int $refundOf = null): Payment => Payment::factory()->create([
        'folio_id' => $folio->id,
        'payment_method_id' => $cash->id,
        'amount' => $amount,
        'refund_of_id' => $refundOf,
        ...($refundOf === null ? [] : ['receipt_number' => null]),
        'folio_line_id' => FolioLine::factory()->payment($amount)->create(['folio_id' => $folio->id, 'transaction_code_id' => $cash->transaction_code_id, 'posting_date' => '2026-10-12'])->id,
    ]);
    $first = $paid('200.00');
    $paid('20.00', $first->id);

    $dinner = MealPeriod::factory()->create(['property_id' => $property->id, 'name' => 'Dinner', 'starts_at' => '19:00', 'ends_at' => '20:00']);
    $plan = RatePlan::factory()->create(['property_id' => $property->id]);
    RatePlanInclusion::factory()->create(['rate_plan_id' => $plan->id, 'meal_period_id' => $dinner->id, 'starts_on' => InclusionStart::ArrivalDay, 'ends_on' => InclusionEnd::NightBeforeDeparture]);
    ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]))->checkedIn()
        ->create(['rate_plan_id' => $plan->id, 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-15', 'adults' => 2, 'children' => 1]);

    $openUnit = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]))->checkedIn()->create();
    $resolvedUnit = ReservationUnit::factory()->for(Reservation::factory()->create(['property_id' => $property->id]))->checkedIn()->create();
    NightAuditException::factory()->create(['night_audit_id' => $audit->id, 'reservation_unit_id' => $openUnit->id]);
    NightAuditException::factory()->resolved()->create(['night_audit_id' => $audit->id, 'reservation_unit_id' => $resolvedUnit->id]);
    NightAuditStep::factory()->create(['night_audit_id' => $audit->id, 'step' => AuditStep::PostRoomCharges, 'status' => AuditStepStatus::Completed, 'processed_count' => 7, 'failed_count' => 2]);

    $captured = capturePacketView();
    $this->actingAs($user)->get(packetUrl($property))->assertOk();
    $html = packetHtml($captured);

    expect($html)->toContain('Night audit — 12 Oct 2026', $user->name, 'Run by staff')
        ->not->toContain('Forced:')
        ->toContain('Rooms available', 'Beds sold', '50.00', '180.00', '90.00', 'In-house guests')
        ->toContain('ROOM', 'Room revenue', '900.00')
        ->toContain('Payments by method', 'Cash', '200.00', 'Refunds', '-20.00', '180.00')
        ->toContain('Meals for 13 Oct 2026 (as at printing)', 'Dinner')
        ->toContain($openUnit->reservation->reference, $resolvedUnit->reservation->reference, 'Open', 'Resolved', 'folio was closed')
        ->toContain('Audit steps', 'Post room charges');
});

test('a forced audit shows its reason, and the meals section is left out when no plan includes a meal', function () {
    [$user, $property, $audit] = packetSetup();
    $audit->forceFill(['forced' => true, 'force_reason' => 'Power cut at midnight'])->save();
    $captured = capturePacketView();

    $this->actingAs($user)->get(packetUrl($property))->assertOk();
    $html = packetHtml($captured);

    expect($html)->toContain('Forced: Power cut at midnight')
        ->not->toContain('as at printing');
});

test('a city ledger payment counts in the payments of the packet and a voided one does not', function () {
    [$user, $property] = packetSetup();
    $bank = PaymentMethod::factory()->create(['property_id' => $property->id, 'name' => 'Bank transfer']);
    ArPayment::factory()->create(['property_id' => $property->id, 'payment_method_id' => $bank->id, 'amount' => '500.00', 'received_on' => '2026-10-12']);
    ArPayment::factory()->voided()->create(['property_id' => $property->id, 'payment_method_id' => $bank->id, 'amount' => '70.00', 'received_on' => '2026-10-12']);
    ArPayment::factory()->create(['property_id' => $property->id, 'payment_method_id' => $bank->id, 'amount' => '33.00', 'received_on' => '2026-10-11']);
    $captured = capturePacketView();

    $this->actingAs($user)->get(packetUrl($property))->assertOk();

    expect(packetHtml($captured))->toContain('Bank transfer', '500.00')
        ->not->toContain('70.00')
        ->not->toContain('33.00');
});
