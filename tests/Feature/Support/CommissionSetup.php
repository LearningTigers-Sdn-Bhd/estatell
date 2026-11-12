<?php

require_once __DIR__.'/CurlecSetup.php';

use App\Enums\CommissionService;
use App\Enums\GatewayRefundStatus;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Enums\UnitStatus;
use App\Models\BusinessDate;
use App\Models\ChannelBookingRevision;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\GatewayPayment;
use App\Models\GatewayRefund;
use App\Models\Property;
use App\Models\PropertyCommissionPeriod;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;

/**
 * A confirmed online booking paid through the Curlec webhook: RM 200 for each room, a booked room total of RM 200 for each
 * room before tax, and Curlec's fee on the payment in sen (null leaves the fee out of the payment entity). The property
 * is charged booking engine commission from 2026-01-01.
 *
 * @return array<string, mixed> The `curlecSetup()` keys plus `hold`, `payment` and `reservation`.
 */
function commissionBooking(int $rooms = 1, ?int $curlecFeeSen = 472): array
{
    $setup = curlecSetup();
    startCommission($setup['property']->id, CommissionService::BookingEngine);
    $hold = curlecHold($setup, rooms: $rooms);
    curlecOrder($hold);

    $paid = curlecOrderPaid();
    if ($curlecFeeSen !== null) {
        $paid['payload']['payment']['entity'] += ['fee' => $curlecFeeSen, 'tax' => 72];
    }
    postCurlecWebhook($paid)->assertOk();

    $hold->refresh();

    return [
        ...$setup,
        'hold' => $hold,
        'payment' => GatewayPayment::query()->where('booking_hold_id', $hold->id)->sole(),
        'reservation' => Reservation::query()->findOrFail($hold->reservation_id),
    ];
}

/**
 * Another paid online booking on the property of `commissionBooking()`, with its own Curlec order.
 *
 * @param  array<string, mixed>  $setup
 */
function anotherCommissionBooking(array $setup, string $orderId = 'order_two'): Reservation
{
    $hold = curlecHold($setup);
    curlecOrder($hold, $orderId);
    postCurlecWebhook(curlecOrderPaid($orderId, 'pay_'.$orderId), 'evt_'.$orderId)->assertOk();

    return Reservation::query()->findOrFail($hold->refresh()->reservation_id);
}

/**
 * Posts a line with a system code on the reservation's first folio. Charges and their discounts only; tax lines use
 * `TransactionKind::Tax`.
 */
function commissionFolioLine(Reservation $reservation, SystemTransactionCode $systemCode, string $amount, TransactionKind $kind = TransactionKind::Charge, ?FolioLine $parent = null): FolioLine
{
    $code = TransactionCode::query()->where('property_id', $reservation->property_id)->where('system_key', $systemCode->value)->first()
        ?? TransactionCode::factory()->for($reservation->property)->system($systemCode)->create();

    return FolioLine::factory()->create([
        'folio_id' => $reservation->folios()->orderBy('id')->value('id'),
        'transaction_code_id' => $code->id,
        'kind' => $kind,
        'unit_amount' => $amount,
        'amount' => $amount,
        'parent_line_id' => $parent?->id,
    ]);
}

/**
 * Ends every unit of the reservation with the given status.
 */
function endCommissionStay(Reservation $reservation, UnitStatus $status = UnitStatus::CheckedOut): void
{
    $reservation->units()->each(fn (ReservationUnit $unit) => endCommissionUnit($unit, $status));
}

/**
 * Ends one unit. A checked-out unit gets its check-in and check-out times, as the database requires.
 */
function endCommissionUnit(ReservationUnit $unit, UnitStatus $status): void
{
    ReservationUnit::query()->whereKey($unit->id)->update([
        'status' => $status,
        'checked_in_at' => $status === UnitStatus::CheckedOut ? now()->subDay() : null,
        'checked_out_at' => $status === UnitStatus::CheckedOut ? now() : null,
    ]);
}

function processedCommissionRefund(GatewayPayment $payment, string $amount): GatewayRefund
{
    return GatewayRefund::factory()->create([
        'gateway_payment_id' => $payment->id,
        'property_id' => $payment->property_id,
        'amount' => $amount,
        'status' => GatewayRefundStatus::Processed,
        'gateway_refund_id' => 'rfnd_'.fake()->unique()->numerify('#####'),
    ]);
}

/**
 * An OTA reservation with one unit (departing 2026-10-14) and a master folio, on a live property with an open business
 * date of today. `fromChannex` adds the Channex revision that marks it as a channel manager booking. `chargedFrom` starts
 * the property's channel manager charging period (null leaves it uncharged).
 */
function channelCommissionReservation(bool $fromChannex = true, ?string $chargedFrom = '2026-10-01'): Reservation
{
    $property = Property::factory()->live()->create();

    if ($chargedFrom !== null) {
        startCommission($property->id, CommissionService::ChannelManager, $chargedFrom);
    }

    BusinessDate::factory()->create(['property_id' => $property->id]);
    $reservation = Reservation::factory()->ota()->create(['property_id' => $property->id]);
    ReservationUnit::factory()->create(['reservation_id' => $reservation->id, 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);
    Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $reservation->id]);

    if ($fromChannex) {
        ChannelBookingRevision::factory()->create(['reservation_id' => $reservation->id]);
    }

    return $reservation;
}

/**
 * A charging period for a property and service, open-ended unless `endsOn` is given.
 */
function startCommission(int $propertyId, CommissionService $service, string $startsOn = '2026-01-01', ?string $endsOn = null): PropertyCommissionPeriod
{
    return PropertyCommissionPeriod::factory()->create(['property_id' => $propertyId, 'service' => $service, 'starts_on' => $startsOn, 'ends_on' => $endsOn]);
}
