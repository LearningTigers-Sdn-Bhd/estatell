<?php

require_once __DIR__.'/CurlecSetup.php';

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
use App\Models\PlatformSetting;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Services\Platform\PlatformSettings;
use Illuminate\Support\Facades\Cache;

/**
 * A confirmed online booking paid through the Curlec webhook: RM 200 for each room, a booked room total of RM 200 for each
 * room before tax, and Curlec's fee on the payment in sen (null leaves the fee out of the payment entity).
 *
 * @return array<string, mixed> The `curlecSetup()` keys plus `hold`, `payment` and `reservation`.
 */
function commissionBooking(int $rooms = 1, ?int $curlecFeeSen = 472): array
{
    $setup = curlecSetup();
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
 * date of today. `fromChannex` adds the Channex revision that marks it as a channel manager booking.
 */
function channelCommissionReservation(bool $fromChannex = true): Reservation
{
    $property = Property::factory()->live()->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $reservation = Reservation::factory()->ota()->create(['property_id' => $property->id]);
    ReservationUnit::factory()->create(['reservation_id' => $reservation->id, 'arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);
    Folio::factory()->create(['property_id' => $property->id, 'reservation_id' => $reservation->id]);

    if ($fromChannex) {
        ChannelBookingRevision::factory()->create(['reservation_id' => $reservation->id]);
    }

    return $reservation;
}

function setChannelManagerCommissionFrom(?string $date): void
{
    PlatformSetting::query()->whereKey(1)->update(['channel_manager_commission_from' => $date]);
    Cache::forget(PlatformSettings::CACHE_KEY);
}
