<?php

namespace App\Actions\Commissions;

use App\Data\Commissions\CommissionEntryInputData;
use App\Models\CommissionEntry;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of the commission ledger. A repeat with the same idempotency key returns the existing entry and
 * writes nothing. See docs/30-commission-fees/03-ledger.md#rules.
 */
class PostCommissionEntry
{
    public function __invoke(Property $property, CommissionEntryInputData $data, ?User $actor = null): CommissionEntry
    {
        if ($data->idempotencyKey !== null && ($existing = $this->existing($property, $data->idempotencyKey)) !== null) {
            return $existing;
        }

        try {
            // A savepoint, so a lost race inside a caller's transaction can still read the winner's entry.
            return DB::transaction(fn (): CommissionEntry => CommissionEntry::query()->create([
                'property_id' => $property->id,
                'reservation_id' => $data->reservationId,
                'type' => $data->type,
                'service' => $data->service,
                'base_amount' => $data->baseAmount,
                'rate' => $data->rate,
                'amount' => $data->amount,
                'due_date' => $data->dueDate,
                'gateway_payment_id' => $data->gatewayPaymentId,
                'gateway_refund_id' => $data->gatewayRefundId,
                'reverses_entry_id' => $data->reversesEntryId,
                'idempotency_key' => $data->idempotencyKey,
                'reason' => $data->reason,
                'created_by' => $actor?->id,
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            if ($data->idempotencyKey === null) {
                throw $exception;
            }

            return $this->existing($property, $data->idempotencyKey) ?? throw $exception;
        }
    }

    private function existing(Property $property, string $idempotencyKey): ?CommissionEntry
    {
        return CommissionEntry::query()
            ->where('property_id', $property->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }
}
