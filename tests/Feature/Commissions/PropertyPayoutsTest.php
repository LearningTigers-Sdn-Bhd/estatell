<?php

require_once __DIR__.'/../Support/CommissionSetup.php';

use App\Actions\Commissions\RecordDueCommissions;
use App\Enums\CommissionService;
use App\Enums\DefaultRoleEnum;
use App\Enums\PayoutVoucherStatus;
use App\Enums\PermissionEnum;
use App\Enums\SystemTransactionCode;
use App\Models\PayoutVoucher;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * An ended booking engine booking (paid RM 200, commission RM 20) on its own property, and an ended channel manager
 * booking on another property, both recorded on 2026-10-14.
 *
 * @return array<string, mixed> The `commissionBooking()` keys plus `other`, the other property's reservation.
 */
function payoutBookings(): array
{
    $setup = commissionBooking();
    commissionFolioLine($setup['reservation'], SystemTransactionCode::RoomRevenue, '200.00');
    endCommissionStay($setup['reservation']);

    $other = channelCommissionReservation();
    commissionFolioLine($other, SystemTransactionCode::RoomRevenue, '300.00');
    endCommissionStay($other);

    app(RecordDueCommissions::class)();

    return [...$setup, 'other' => $other];
}

function payoutsOwner(Property $property): User
{
    return User::factory()->for($property)->withPermissions(DefaultRoleEnum::Owner->permissions())->create();
}

/**
 * Whether the staff sidebar holds a Payouts item.
 */
function hasPayoutsNav(Assert $page): Assert
{
    return $page->where('navigation.contexts', fn (Collection $contexts): bool => collect($contexts)
        ->flatMap(fn (array $context): array => $context['groups'])
        ->flatMap(fn (array $group): array => $group['items'])
        ->contains('title', 'Payouts'));
}

test('the bookings tab shows only the property\'s bookings and its next payout', function () {
    $setup = payoutBookings();
    $property = $setup['property'];

    $this->actingAs(payoutsOwner($property))
        ->get(route('finance.payouts.index', $property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => hasPayoutsNav($page
            ->component('finance/payouts/index')
            ->has('rows.data', 1)
            ->where('rows.data.0.reservationId', $setup['reservation']->id)
            ->where('rows.data.0.value', '200.00')
            ->where('rows.data.0.commissions', ['booking_engine' => '20.00', 'channel_manager' => '0.00'])
            ->where('rows.data.0.outstanding', '180.00')
            ->where('footer.outstanding', '180.00')
            ->where('summary.nextAmount', '180.00')
            ->where('summary.nextDueOn', '2026-11-02')
            ->where('summary.lastAmount', null)
            ->where('canOpenReservations', true)));
});

test('the search keeps bookings by reference or guest name', function (string $search, int $rows) {
    $setup = payoutBookings();
    $property = $setup['property'];
    $search = match ($search) {
        'reference' => $setup['reservation']->reference,
        'guest' => $setup['reservation']->booker->full_name,
        default => $search,
    };

    $this->actingAs(payoutsOwner($property))
        ->get(route('finance.payouts.index', ['property' => $property, 'filter' => ['search' => $search]]))
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', $rows));
})->with([
    'the reference' => ['reference', 1],
    'the guest name' => ['guest', 1],
    'no match' => ['no such booking', 0],
]);

test('the export has the page columns without the property', function () {
    $setup = payoutBookings();
    $property = $setup['property'];

    $response = $this->actingAs(payoutsOwner($property))
        ->get(route('finance.payouts.export', $property))
        ->assertOk()
        ->assertDownload('payouts-2026-10-01-to-2026-10-31.csv');

    $csv = file_get_contents($response->baseResponse->getFile()->getPathname());

    expect($csv)->toStartWith("\u{FEFF}".'Booking,Source,Booked,Check-in,Check-out,Guest,Value,"Booking engine commission","Channel manager commission","Outstanding to hotel"')
        ->and($csv)->toContain($setup['reservation']->reference)
        ->and($csv)->not->toContain($setup['other']->reference);
});

test('the next payout is the open voucher and reads as owed when negative, and the last payout is the latest paid one', function () {
    $property = Property::factory()->create();
    startCommission($property->id, CommissionService::ChannelManager);
    PayoutVoucher::factory()->create(['property_id' => $property->id, 'period_start' => '2026-08-16', 'period_end' => '2026-09-15', 'due_on' => '2026-10-01', 'number' => 'PV-OLD', 'amount' => '310.00', 'status' => PayoutVoucherStatus::Paid, 'paid_on' => '2026-10-01', 'paid_reference' => 'MBB-889']);
    PayoutVoucher::factory()->create(['property_id' => $property->id, 'amount' => '-60.00', 'status' => PayoutVoucherStatus::Approved]);

    $this->actingAs(payoutsOwner($property))
        ->get(route('finance.payouts.index', $property))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.nextAmount', '-60.00')
            ->where('summary.nextDueOn', '2026-11-02')
            ->where('summary.lastAmount', '310.00')
            ->where('summary.lastPaidOn', '2026-10-01')
            ->where('summary.lastReference', 'MBB-889'));
});

test('the payouts tab lists the property\'s vouchers without drafts', function () {
    $property = Property::factory()->create();
    startCommission($property->id, CommissionService::BookingEngine);
    $paid = PayoutVoucher::factory()->create(['property_id' => $property->id, 'period_start' => '2026-08-16', 'period_end' => '2026-09-15', 'number' => 'PV-PAID', 'status' => PayoutVoucherStatus::Paid, 'paid_on' => '2026-10-01']);
    PayoutVoucher::factory()->create(['property_id' => $property->id, 'number' => 'PV-DRAFT']);
    PayoutVoucher::factory()->create(['number' => 'PV-OTHER', 'status' => PayoutVoucherStatus::Paid]);

    $this->actingAs(payoutsOwner($property))
        ->get(route('finance.payouts.vouchers.index', $property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/payouts/vouchers/index')
            ->has('vouchers.data', 1)
            ->where('vouchers.data.0.id', $paid->id)
            ->where('vouchers.data.0.paidOn', '2026-10-01'));
});

test('a voucher opens read-only with its PDF', function () {
    $property = Property::factory()->create();
    $voucher = PayoutVoucher::factory()->create(['property_id' => $property->id, 'status' => PayoutVoucherStatus::Approved, 'approved_by' => User::factory()->admin(), 'approved_at' => now()]);
    $owner = payoutsOwner($property);

    $this->actingAs($owner)
        ->get(route('finance.payouts.vouchers.show', [$property, $voucher]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('finance/payouts/vouchers/show')
            ->where('voucher.voucher.id', $voucher->id)
            ->where('voucher.approvedBy', null)
            ->where('voucher.canApprove', false)
            ->where('voucher.canMarkPaid', false)
            ->where('voucher.canAdjust', false));

    $this->get(route('finance.payouts.vouchers.pdf', [$property, $voucher]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('a draft voucher or another property\'s voucher is not found', function () {
    $property = Property::factory()->create();
    startCommission($property->id, CommissionService::BookingEngine);
    $draft = PayoutVoucher::factory()->create(['property_id' => $property->id]);
    $other = PayoutVoucher::factory()->create(['status' => PayoutVoucherStatus::Paid]);

    $this->actingAs(payoutsOwner($property));

    $this->get(route('finance.payouts.vouchers.show', [$property, $draft]))->assertNotFound();
    $this->get(route('finance.payouts.vouchers.pdf', [$property, $draft]))->assertNotFound();
    $this->get(route('finance.payouts.vouchers.show', [$property, $other]))->assertNotFound();
});

test('staff without the permission are forbidden and do not see the sidebar item', function () {
    $property = Property::factory()->create();
    startCommission($property->id, CommissionService::BookingEngine);
    $staff = User::factory()->for($property)->withPermissions([PermissionEnum::CompanyView])->create();

    $this->actingAs($staff)->get(route('finance.payouts.index', $property))->assertForbidden();
    $this->get(route('finance.payouts.vouchers.index', $property))->assertForbidden();
    $this->get(route('finance.city-ledger.companies.index', $property))
        ->assertInertia(fn (Assert $page) => $page->where('navigation.contexts', fn (Collection $contexts): bool => ! collect($contexts)
            ->flatMap(fn (array $context): array => $context['groups'])
            ->flatMap(fn (array $group): array => $group['items'])
            ->contains('title', 'Payouts')));
});

test('the page is hidden until a service is on or a voucher is visible', function () {
    $property = Property::factory()->create();
    $owner = payoutsOwner($property);
    PayoutVoucher::factory()->create(['property_id' => $property->id]);

    $this->actingAs($owner)->get(route('finance.payouts.index', $property))->assertNotFound();
    $this->get(route('finance.payouts.vouchers.index', $property))->assertNotFound();
    $this->get(route('finance.city-ledger.companies.index', $property))
        ->assertInertia(fn (Assert $page) => $page->where('navigation.contexts', fn (Collection $contexts): bool => ! collect($contexts)
            ->flatMap(fn (array $context): array => $context['groups'])
            ->flatMap(fn (array $group): array => $group['items'])
            ->contains('title', 'Payouts')));
});

test('the page stays after every service stops while a paid voucher exists', function () {
    $property = Property::factory()->create();
    startCommission($property->id, CommissionService::BookingEngine, '2026-01-01', '2026-09-30');
    PayoutVoucher::factory()->create(['property_id' => $property->id, 'status' => PayoutVoucherStatus::Paid]);

    $this->actingAs(payoutsOwner($property))
        ->get(route('finance.payouts.vouchers.index', $property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('vouchers.data', 1));
});

test('another property\'s payouts are not found', function () {
    $property = Property::factory()->create();
    $other = Property::factory()->create();
    startCommission($other->id, CommissionService::BookingEngine);

    $this->actingAs(payoutsOwner($property))->get(route('finance.payouts.index', $other))->assertNotFound();
});
