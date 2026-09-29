<?php

namespace App\Actions\Catalogues;

use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateRoomRevenueTaxes
{
    /**
     * Link the same taxes to every room revenue code: room nights, no-shows,
     * cancellations, late checkouts and early departures.
     *
     * @param  list<int>  $taxIds  Validated tax ids from the property.
     */
    public function __invoke(Property $property, User $actor, array $taxIds): void
    {
        DB::transaction(function () use ($property, $actor, $taxIds): void {
            sort($taxIds);
            $changed = false;

            $roomCodes = $property->transactionCodes()
                ->whereIn('system_key', SystemTransactionCode::roomRevenueKeys())
                ->with('applicableTaxes')
                ->lockForUpdate()
                ->get();

            foreach ($roomCodes as $roomCode) {
                $currentTaxIds = $this->taxIdsOf($roomCode);

                if ($currentTaxIds === $taxIds) {
                    continue;
                }

                $roomCode->applicableTaxes()->sync($taxIds);
                $changed = true;
            }

            if (! $changed) {
                return;
            }

            activity()
                ->causedBy($actor)
                ->event('room_revenue.taxes_updated')
                ->withProperties(['property_id' => $property->id, 'tax_ids' => $taxIds])
                ->log('Room revenue taxes updated');
        });
    }

    /** @return list<int> */
    private function taxIdsOf(TransactionCode $transactionCode): array
    {
        return array_values($transactionCode->applicableTaxes
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->all());
    }
}
