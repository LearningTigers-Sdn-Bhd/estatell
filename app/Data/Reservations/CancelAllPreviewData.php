<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Cancel reservation form lists when it opens: one row for each unit of the reservation. A row is
 * `canCancel` when the unit is Confirmed, and otherwise carries a `note`. `fee` is the policy fee before tax and
 * `feeTotal` the same with tax; both are read only text and never sent back as an amount. `paid` is the net payment
 * held on the reservation (folios that are not Voided, without the city ledger line). `request` is the company
 * portal cancellation request, when one is open. A row's `onlineRefund` is what its cancel gives back through Curlec,
 * and `onlineRefundWaived` the same with Do not charge. `facilityBookings` is the reservation's live facility bookings:
 * they are cancelled too when every unit of `liveUnitIds` is cancelled.
 */
class CancelAllPreviewData extends Data
{
    /**
     * @param  list<array{unitId: int, label: string, guest: string|null, arrival: string, departure: string, stayLabel: string|null, canCancel: bool, fee: string|null, feeTotal: string|null, withinFreeWindow: bool, unpostedAddOns: int, onlineRefund: string, onlineRefundWaived: string, note: string|null}>  $rows
     * @param  array{by: string, at: string}|null  $request
     * @param  list<int>  $liveUnitIds  Units that are not Cancelled or No-show
     */
    public function __construct(
        public array $rows,
        public ?int $freeUntilDays,
        public string $paid,
        public ?array $request,
        public ?string $error,
        public int $facilityBookings = 0,
        public array $liveUnitIds = [],
    ) {}

    public static function refused(string $error): self
    {
        return new self([], null, '0.00', null, $error);
    }
}
