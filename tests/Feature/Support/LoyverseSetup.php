<?php

use App\Enums\PosConnectionStatus;
use App\Enums\PosProvider;
use App\Models\PosConnection;
use App\Models\PosGuestLink;
use App\Models\PosOutletMapping;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Room;
use App\Models\UnitNight;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/PosSetup.php';

/**
 * posSetup() plus Loyverse app keys, a Connected Loyverse connection with fresh tokens and a webhook, and an active
 * mapping of store `store-1` to the outlet with `pt-room` as its Room Charge payment type.
 *
 * @param  array<string, mixed>  $connection  Overrides for the connection.
 * @return array<string, mixed>
 */
function loyverseSetup(array $connection = []): array
{
    config(['services.loyverse.client_id' => 'cid', 'services.loyverse.client_secret' => 'sec', 'services.loyverse.base_url' => 'https://api.loyverse.com']);
    $setup = posSetup();

    $loyverse = new PosConnection;
    $loyverse->property_id = $setup['property']->id;
    $loyverse->forceFill([
        'provider' => PosProvider::Loyverse,
        'status' => PosConnectionStatus::Connected,
        'external_merchant_id' => 'merch-1',
        'external_webhook_id' => 'wh-1',
        'access_token' => 'at-1',
        'refresh_token' => 'rt-1',
        'token_expires_at' => now()->addHours(10),
        ...$connection,
    ])->save();

    $mapping = new PosOutletMapping;
    $mapping->property_id = $setup['property']->id;
    $mapping->pos_connection_id = $loyverse->id;
    $mapping->fill(['pos_outlet_id' => $setup['outlet']->id, 'external_store_id' => 'store-1', 'external_payment_type_id' => 'pt-room', 'active' => true])->save();

    return [...$setup, 'connection' => $loyverse, 'mapping' => $mapping];
}

/**
 * Puts the setup's checked-in unit in a room for the business date (2026-10-14).
 *
 * @param  array<string, mixed>  $setup
 */
function loyverseRoom(array $setup, string $number = '301'): Room
{
    $room = Room::factory()->create(['property_id' => $setup['property']->id, 'number' => $number]);
    UnitNight::query()->where('reservation_unit_id', $setup['unit']->id)->delete();
    UnitNight::factory()->create(['reservation_unit_id' => $setup['unit']->id, 'date' => '2026-10-14', 'room_id' => $room->id]);

    return $room;
}

/**
 * A guest link so a receipt with `customer_id` `cust-1` lands on the setup's unit.
 *
 * @param  array<string, mixed>  $setup
 */
function loyverseLink(array $setup, string $customerId = 'cust-1', string $label = 'Room 301 - Guest'): PosGuestLink
{
    $link = new PosGuestLink;
    $link->property_id = $setup['property']->id;
    $link->pos_connection_id = $setup['connection']->id;
    $link->reservation_unit_id = $setup['unit']->id;
    $link->fill(['external_customer_id' => $customerId, 'synced_label' => $label, 'last_synced_at' => now()])->save();

    return $link;
}

/**
 * A Loyverse sale of 100.00 with a split payment: 14.50 cash and 85.50 on the Room Charge payment type.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function loyverseReceipt(array $overrides = []): array
{
    return [
        'receipt_number' => '2-1008',
        'receipt_type' => 'SALE',
        'refund_for' => null,
        'cancelled_at' => null,
        'store_id' => 'store-1',
        'customer_id' => 'cust-1',
        'note' => null,
        'total_money' => 100.0,
        'payments' => [
            ['payment_type_id' => 'pt-cash', 'name' => 'Cash', 'type' => 'CASH', 'money_amount' => 14.5],
            ['payment_type_id' => 'pt-room', 'name' => 'Room Charge', 'type' => 'OTHER', 'money_amount' => 85.5],
        ],
        ...$overrides,
    ];
}

/**
 * Sends a signed `receipts.update` webhook the way Loyverse does: the HMAC-SHA1 of the raw body, in hex, keyed with the app's
 * client secret. The queue is `sync` in tests, so the receipts are processed before this returns.
 *
 * @param  list<array<string, mixed>>  $receipts
 */
function loyverseWebhook(array $receipts, string $merchantId = 'merch-1', ?string $signature = null, string $type = 'receipts.update'): TestResponse
{
    $body = json_encode(['merchant_id' => $merchantId, 'type' => $type, 'created_at' => '2026-10-14T04:00:00.000Z', 'receipts' => $receipts], JSON_THROW_ON_ERROR);
    $headers = ['CONTENT_TYPE' => 'application/json'];

    if ($signature !== '') {
        $headers['HTTP_X_LOYVERSE_SIGNATURE'] = $signature ?? hash_hmac('sha1', $body, 'sec');
    }

    return test()->call('POST', '/webhooks/loyverse', [], [], [], $headers, $body);
}

/**
 * A checked-in unit in the setup's property, so tests can build a second one.
 *
 * @param  array<string, mixed>  $setup
 */
function loyverseOtherUnit(array $setup): ReservationUnit
{
    $reservation = Reservation::factory()->create(['property_id' => $setup['property']->id]);

    return ReservationUnit::factory()->checkedIn()->create(['reservation_id' => $reservation->id]);
}
