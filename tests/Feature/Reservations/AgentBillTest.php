<?php

use App\Actions\Folio\ReverseLine;
use App\Actions\NightAudit\PostRoomCharges;
use App\Enums\BillingMode;
use App\Enums\ChargingUnit;
use App\Enums\FolioType;
use App\Enums\InclusionStart;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioRoutingRule;
use App\Models\Guest;
use App\Models\PropertySetting;
use App\Models\RatePlanInclusion;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitAddOn;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Reservations\AgentBill;
use App\Services\Reservations\AgentPaymentSchedule;
use Carbon\CarbonImmutable;

/**
 * An agent booking of 2 nights at 300.00 for a foreign guest: the room goes to the agent's Master folio, a RM10 tourism
 * tax per night is collected at check-in and a jetty fee of 2 × RM10 is an add-on. 50% deposit schedule.
 *
 * @return array{user: User, unit: ReservationUnit, reservation: Reservation, company: Company, master: Folio}
 */
function agentBillSetup(bool $companyPaysTax = false, BillingMode $mode = BillingMode::MasterPays): array
{
    test()->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
    $user = User::factory()->owner()->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    $room = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id, 'name' => 'Room']);
    $tax = Tax::factory()->for($property)->create(['name' => 'Tourism tax', 'rate_type' => TaxRateType::Flat, 'rate' => '10.00', 'foreign_guests_only' => true, 'collect_at_check_in' => true, 'enabled' => true]);
    $tax->applicableTransactionCodes()->attach($room);
    $company = Company::factory()->for($property)->create(['direct_bill_enabled' => false, 'pays_collected_taxes' => $companyPaysTax]);
    $guest = Guest::factory()->create(['property_id' => $property->id, 'nationality_code' => 'CN']);
    $reservation = Reservation::factory()->create([
        'property_id' => $property->id, 'company_id' => $company->id, 'booker_guest_id' => $guest->id, 'billing_mode' => $mode,
        'deposit_percent' => 50, 'deposit_due_at' => '2026-10-13 00:00:00', 'full_payment_due_at' => '2026-10-11 00:00:00',
    ]);
    $unit = ReservationUnit::factory()->for($reservation)->withNights()->checkedIn()->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14', 'adults' => 2]);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => '300.00', 'amount' => '300.00']);
    $master = Folio::factory()->master()->create(['property_id' => $property->id, 'reservation_id' => $reservation->id, 'payer_company_id' => $company->id]);
    FolioRoutingRule::factory()->create(['reservation_id' => $reservation->id, 'category' => $mode === BillingMode::MasterPays ? TransactionCategory::Room : null, 'target_folio_id' => $master->id]);
    $item = ChargeItem::factory()->create(['property_id' => $property->id, 'name' => 'Jetty Fee']);
    UnitAddOn::factory()->create(['reservation_unit_id' => $unit->id, 'charge_item_id' => $item->id, 'quantity' => 2, 'unit_amount' => '10.00']);

    return compact('user', 'unit', 'reservation', 'company', 'master');
}

function agentBillShape(array $lines): array
{
    return array_map(fn (array $line): array => [$line['label'], $line['quantity'], $line['amount']], $lines);
}

test('on Master pays the agent pays the room, and the guest pays the tourism tax and the jetty fee', function () {
    $setup = agentBillSetup();

    $bill = app(AgentBill::class)->for($setup['reservation']);

    expect(agentBillShape($bill->agentLines))->toBe([['Room', 2, '600.00']])
        ->and($bill->agentTotal)->toBe('600.00')
        ->and(agentBillShape($bill->guestLines))->toBe([['Jetty Fee', 2, '20.00'], ['Tourism tax', 2, '20.00']])
        ->and($bill->guestTotal)->toBe('40.00')
        ->and($bill->total)->toBe('640.00');
});

test('on Master pays a package inclusion is billed to the agent with the room', function () {
    $setup = agentBillSetup();
    $fare = ChargeItem::factory()->create(['property_id' => $setup['unit']->property_id, 'name' => 'Speedboat Fare']);
    $fare->transactionCode->forceFill(['category' => TransactionCategory::Transport])->save();
    RatePlanInclusion::factory()->create(['rate_plan_id' => $setup['unit']->rate_plan_id, 'charge_item_id' => $fare->id, 'charging_unit' => ChargingUnit::PerGuest, 'starts_on' => InclusionStart::ArrivalDay, 'allocated_amount' => '15.00']);

    $bill = app(AgentBill::class)->for($setup['reservation']);

    expect(agentBillShape($bill->agentLines))->toBe([['Room', 2, '570.00'], ['Speedboat Fare', 1, '30.00']])
        ->and(agentBillShape($bill->guestLines))->toBe([['Jetty Fee', 2, '20.00'], ['Tourism tax', 2, '20.00']])
        ->and($bill->total)->toBe('640.00');
});

test('Company pays tourism tax moves the tax to the agent', function () {
    $bill = app(AgentBill::class)->for(agentBillSetup(companyPaysTax: true)['reservation']);

    expect(agentBillShape($bill->agentLines))->toBe([['Room', 2, '600.00'], ['Tourism tax', 2, '20.00']])
        ->and(agentBillShape($bill->guestLines))->toBe([['Jetty Fee', 2, '20.00']]);
});

test('Master pays all moves the jetty fee to the agent and the tourism tax stays with the guest', function () {
    $bill = app(AgentBill::class)->for(agentBillSetup(mode: BillingMode::MasterPaysAll)['reservation']);

    expect(agentBillShape($bill->agentLines))->toBe([['Room', 2, '600.00'], ['Jetty Fee', 2, '20.00']])
        ->and(agentBillShape($bill->guestLines))->toBe([['Tourism tax', 2, '20.00']]);
});

test('a posted night stays in the same part and the bill does not change', function () {
    $setup = agentBillSetup();
    $before = app(AgentBill::class)->for($setup['reservation']);

    app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse('2026-10-12'));

    $after = app(AgentBill::class)->for($setup['reservation']);

    expect(agentBillShape($after->agentLines))->toBe(agentBillShape($before->agentLines))
        ->and(agentBillShape($after->guestLines))->toEqualCanonicalizing(agentBillShape($before->guestLines))
        ->and($after->total)->toBe($before->total);
});

test('a reversed night leaves the bill, and is not scheduled again', function () {
    $setup = agentBillSetup();
    app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse('2026-10-12'));
    $line = FolioLine::query()->where('folio_id', $setup['master']->id)->where('kind', TransactionKind::Charge)->sole();

    app(ReverseLine::class)($setup['user'], $line);

    $bill = app(AgentBill::class)->for($setup['reservation']);

    expect(agentBillShape($bill->agentLines))->toBe([['Room', 1, '300.00']]);
});

test('the deposit counts only the agent part, and a guest payment never pays the agent', function () {
    $setup = agentBillSetup();
    $schedule = app(AgentPaymentSchedule::class);

    $unitFolio = Folio::factory()->create(['type' => FolioType::Unit, 'property_id' => $setup['reservation']->property_id, 'reservation_id' => $setup['reservation']->id, 'reservation_unit_id' => $setup['unit']->id]);
    FolioLine::factory()->create(['folio_id' => $unitFolio->id, 'kind' => TransactionKind::Payment, 'amount' => '-40.00']);
    FolioLine::factory()->create(['folio_id' => $setup['master']->id, 'kind' => TransactionKind::Payment, 'amount' => '-300.00']);

    $stages = $schedule->stages($setup['reservation']);

    expect((string) $schedule->total($setup['reservation']))->toBe('600.00')
        ->and((string) $schedule->paid($setup['reservation']))->toBe('300.00')
        ->and($stages[0]['amount'])->toBe('300.00')
        ->and($stages[0]['paid'])->toBeTrue()
        ->and($stages[1]['amount'])->toBe('300.00')
        ->and($stages[1]['paid'])->toBeFalse();
});
