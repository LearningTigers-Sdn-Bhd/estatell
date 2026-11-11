<?php

require_once __DIR__.'/../Support/CommissionSetup.php';

use App\Actions\Commissions\RecordDueCommissions;
use App\Enums\PayoutVoucherStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\UnitStatus;
use App\Models\CommissionEntry;
use App\Models\PayoutVoucher;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Commissions\CommissionTotals;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function dashboardAdmin(): User
{
    return User::factory()->admin()->create();
}

/**
 * A booking engine booking (paid RM 200, commission RM 20) and a channel manager booking (base RM 300, commission RM 9),
 * both recorded on 2026-10-14.
 *
 * @return array{0: Reservation, 1: Reservation, 2: array<string, mixed>}
 */
function recordedBookings(): array
{
    setChannelManagerCommissionFrom('2026-10-01');
    $setup = commissionBooking();
    $online = $setup['reservation'];
    commissionFolioLine($online, SystemTransactionCode::RoomRevenue, '200.00');
    endCommissionStay($online);

    $channel = channelCommissionReservation();
    commissionFolioLine($channel, SystemTransactionCode::RoomRevenue, '300.00');
    endCommissionStay($channel);

    app(RecordDueCommissions::class)();

    return [$online, $channel, $setup];
}

test('each booking is one row with its value, commission per service and outstanding amount', function () {
    [$online, $channel] = recordedBookings();

    $this->actingAs(dashboardAdmin())
        ->get(route('admin.commissions.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/commissions/index')
            ->where('filters.period', 'month')
            ->where('filters.from', '2026-10-01')
            ->where('filters.to', '2026-10-31')
            ->has('rows.data', 2)
            ->where('rows.data.0.reservationId', $channel->id)
            ->where('rows.data.0.value', '300.00')
            ->where('rows.data.0.commissions', ['booking_engine' => '0.00', 'channel_manager' => '9.00'])
            ->where('rows.data.0.outstanding', '-9.00')
            ->where('rows.data.0.checkOut', '2026-10-14')
            ->where('rows.data.1.reservationId', $online->id)
            ->where('rows.data.1.bookingReference', $online->reference)
            ->where('rows.data.1.sourceName', 'Booking Engine')
            ->where('rows.data.1.guestName', $online->booker->full_name)
            ->where('rows.data.1.value', '200.00')
            ->where('rows.data.1.commissions', ['booking_engine' => '20.00', 'channel_manager' => '0.00'])
            ->where('rows.data.1.outstanding', '180.00')
            ->where('footer', ['value' => '500.00', 'commissions' => ['booking_engine' => '20.00', 'channel_manager' => '9.00'], 'outstanding' => '171.00'])
        );
});

test('the period filter keeps entries due inside it', function (string $period, string $date, int $rows) {
    recordedBookings();

    $this->actingAs(dashboardAdmin())
        ->get(route('admin.commissions.index', ['filter' => ['period' => $period, 'date' => $date]]))
        ->assertInertia(fn (Assert $page) => $page->has('rows.data', $rows));
})->with([
    'the day' => ['day', '2026-10-14', 2],
    'the day before' => ['day', '2026-10-13', 0],
    'the week' => ['week', '2026-10-12', 2],
    'last month' => ['month', '2026-09-30', 0],
    'the quarter' => ['quarter', '2026-11-20', 2],
    'the year' => ['year', '2026-01-01', 2],
]);

test('the property and service filters narrow the rows and the footer', function () {
    [$online, $channel] = recordedBookings();

    $this->actingAs(dashboardAdmin())
        ->get(route('admin.commissions.index', ['filter' => ['service' => 'channel_manager']]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows.data', 1)
            ->where('rows.data.0.reservationId', $channel->id)
            ->where('footer.outstanding', '-9.00'));

    $this->get(route('admin.commissions.index', ['filter' => ['property' => $online->property_id]]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows.data', 1)
            ->where('rows.data.0.reservationId', $online->id)
            ->where('totals.cashLiability', '180.00')
            ->where('totals.owedToEstatell', '0.00'));
});

test('the totals hold unpaid balances, money of bookings not ended and what properties owe', function () {
    [, , $setup] = recordedBookings();
    anotherCommissionBooking($setup);

    $totals = app(CommissionTotals::class)->for();

    expect($totals->cashLiability)->toBe('380.00')
        ->and($totals->owedToEstatell)->toBe('9.00')
        ->and($totals->propertiesOwing)->toBe(1)
        ->and($totals->immediatePayable)->toBe('180.00')
        ->and($totals->payableOn)->toBe('2026-11-02');
});

test('a paid voucher no longer counts, and open vouchers set the immediate payable', function () {
    $property = Property::factory()->create();
    $paid = PayoutVoucher::factory()->create(['property_id' => $property->id, 'status' => PayoutVoucherStatus::Paid, 'amount' => '100.00']);
    $entry = CommissionEntry::factory()->create(['property_id' => $property->id, 'amount' => '100.00', 'due_date' => '2026-09-20']);
    CommissionEntry::query()->whereKey($entry->id)->update(['payout_voucher_id' => $paid->id]);

    $other = Property::factory()->create();
    $draft = PayoutVoucher::factory()->create(['property_id' => $other->id, 'number' => 'PV-OPEN', 'amount' => '70.00', 'due_on' => '2026-11-02']);
    $owed = CommissionEntry::factory()->create(['property_id' => $other->id, 'amount' => '70.00', 'due_date' => '2026-09-20']);
    CommissionEntry::query()->whereKey($owed->id)->update(['payout_voucher_id' => $draft->id]);

    $totals = app(CommissionTotals::class)->for();

    expect($totals->cashLiability)->toBe('70.00')
        ->and($totals->immediatePayable)->toBe('70.00')
        ->and($totals->payableOn)->toBe('2026-11-02');
});

test('the CSV has every filtered row with the page columns', function () {
    [$online] = recordedBookings();

    $response = $this->actingAs(dashboardAdmin())
        ->get(route('admin.commissions.export', ['filter' => ['service' => 'booking_engine']]))
        ->assertOk()
        ->assertDownload('commissions-2026-10-01-to-2026-10-31.csv');

    $csv = file_get_contents($response->baseResponse->getFile()->getPathname());

    expect($csv)->toContain('Property,Booking,Source,Booked,Check-in,Check-out,Guest,Value,"Booking engine commission","Channel manager commission","Outstanding to hotel"')
        ->and($csv)->toContain($online->reference)
        ->and(substr_count(trim($csv), "\n"))->toBe(1);
});

test('the commissions page and export are for admins only', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.commissions.index'))->assertNotFound();
    $this->get(route('admin.commissions.export'))->assertNotFound();
});

test('the page runs the same number of queries for more bookings', function () {
    [, , $setup] = recordedBookings();
    $admin = dashboardAdmin();

    DB::enableQueryLog();
    $this->actingAs($admin)->get(route('admin.commissions.index'))->assertOk();
    $few = count(DB::getQueryLog());

    $more = anotherCommissionBooking($setup);
    commissionFolioLine($more, SystemTransactionCode::RoomRevenue, '200.00');
    endCommissionStay($more, UnitStatus::CheckedOut);
    app(RecordDueCommissions::class)();

    DB::flushQueryLog();
    $this->get(route('admin.commissions.index'))->assertOk();

    expect(count(DB::getQueryLog()))->toBe($few);
});
