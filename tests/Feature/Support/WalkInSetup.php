<?php

require_once __DIR__.'/BookingSetup.php';

use App\Enums\PaymentMethodType;
use App\Enums\SystemTransactionCode;
use App\Models\BusinessDate;
use App\Models\PaymentMethod;
use App\Models\Tax;
use App\Models\TransactionCode;

/**
 * The booking setup with an 8% tax on Room revenue flagged Collect at check-in, a 100.00 security deposit and a booker
 * of the property's country. Two nights at 100.00 give 216.00 for each unit, 16.00 of it tax.
 *
 * @return array<string, mixed>
 */
function walkInPaymentSetup(array $settings = [], bool $foreignOnly = false): array
{
    $setup = bookingSetup();
    $setup['property']->settings->update(['security_deposit_amount' => '100.00', ...$settings]);
    $setup['guest']->update(['nationality_code' => 'MY']);
    $room = TransactionCode::factory()->for($setup['property'])->system(SystemTransactionCode::RoomRevenue)->create();
    $setup['tax'] = Tax::factory()->for($setup['property'])->create(['name' => 'Tourism tax', 'rate' => '8.00', 'is_inclusive' => false, 'collect_at_check_in' => true, 'foreign_guests_only' => $foreignOnly]);
    $setup['tax']->applicableTransactionCodes()->attach($room);

    return $setup;
}

/**
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function walkInPaymentBody(array $setup, array $overrides = []): array
{
    return [
        'booking_source_id' => $setup['phone']->id,
        'arrival_date' => '2026-10-20',
        'departure_date' => '2026-10-22',
        'guests' => [['guest_id' => $setup['guest']->id]],
        'billing_mode' => 'each_pays',
        'lines' => [
            ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 2, 'child_ages' => []],
            ['room_type_id' => $setup['roomType']->id, 'rate_plan_id' => $setup['plan']->id, 'adults' => 2, 'child_ages' => []],
        ],
        ...$overrides,
    ];
}

/**
 * The walk-in payment setup, ready to check in: a live property with an open business date on 2026-10-20, no card
 * needed, and a Cash method. Two units of 216.00 (16.00 of it tax) and a 100.00 deposit each.
 *
 * @param  array<string, mixed>  $settings
 * @return array<string, mixed>
 */
function walkInCheckInSetup(array $settings = []): array
{
    $setup = walkInPaymentSetup(['card_required_for_check_in' => false, ...$settings]);
    $setup['property']->forceFill(['status' => 'live'])->save();
    BusinessDate::factory()->create(['property_id' => $setup['property']->id]);
    $setup['cash'] = PaymentMethod::factory()->create(['property_id' => $setup['property']->id, 'type' => PaymentMethodType::Cash, 'requires_reference' => false, 'position' => 1]);

    return $setup;
}

/**
 * The walk-in form as it posts: the Walk-in source, a room on each of two lines, both Payment boxes ticked and the
 * total the rail shows for them (432.00 + 200.00).
 *
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function walkInSaveBody(array $setup, array $overrides = []): array
{
    $line = fn (int $index): array => [
        'room_type_id' => $setup['roomType']->id,
        'rate_plan_id' => $setup['plan']->id,
        'adults' => 2,
        'child_ages' => [],
        'room_id' => $setup['rooms'][$index]->id,
    ];

    return walkInPaymentBody($setup, [
        'booking_source_id' => $setup['walkIn']->id,
        'lines' => [$line(0), $line(1)],
        'collect_room_charges' => true,
        'collect_deposit' => true,
        'payment_method_id' => $setup['cash']->id,
        'expected_total' => '632.00',
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $setup
 */
function walkInSaveUrl(array $setup): string
{
    return route('reservations.store-walk-in', ['property' => $setup['property']->code]);
}
