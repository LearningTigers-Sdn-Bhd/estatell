<?php

namespace App\Data\Reservations;

use App\Actions\FrontDesk\CheckOutGroupResult;
use Spatie\LaravelData\Data;

/**
 * The success state of the group check-out page, sent once as flash data after the save: the totals, the payments
 * recorded (for Print receipts) and the Master next step. `masterUrl` is the Master folio tab, null once the Master is
 * closed. `canSettleMaster` is true while the Master is Open and can be settled to the city ledger by this user.
 */
class GroupCheckOutDoneData extends Data
{
    /**
     * @param  list<int>  $paymentIds
     */
    public function __construct(
        public int $checkedOut,
        public int $settled,
        public string $collected,
        public string $refunded,
        public string $movedToMaster,
        public ?string $invoiceNumber,
        public ?int $invoiceId,
        public array $paymentIds,
        public ?string $masterUrl,
        public bool $canSettleMaster,
    ) {}

    public static function fromResult(CheckOutGroupResult $result, ?GroupCheckOutMasterData $master, ?string $masterUrl): self
    {
        return new self(
            count($result->checkedOutUnitIds),
            count($result->settledUnitIds),
            $result->collected,
            $result->refunded,
            $result->movedToMaster,
            $result->invoice?->number,
            $result->invoice?->id,
            $result->paymentIds,
            $master === null ? null : $masterUrl,
            $master !== null && $master->canSettle && $result->invoice === null,
        );
    }
}
