<?php

namespace App\Actions\Pos;

use App\Models\PosConnection;
use App\Models\PosOutlet;
use App\Models\PosOutletMapping;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Save which Loyverse store posts to which outlet, and which payment type there means "Room Charge". A row without an
 * outlet or a payment type is dropped, and so is any saved mapping that is not in the list. An outlet that is not the
 * property's refuses everything (`outlet_invalid`). Returns null, or a refusal code.
 * See docs/33-pos/04-loyverse-integration.md#store--payment-type-mapping.
 */
class SaveLoyverseMappings
{
    public const REFUSAL_OUTLET_INVALID = 'outlet_invalid';

    /**
     * @param  list<array{store_id: string, pos_outlet_id: int|null, payment_type_id: string|null, active: bool}>  $rows
     */
    public function __invoke(User $actor, Property $property, PosConnection $connection, array $rows): ?string
    {
        $complete = array_values(array_filter($rows, fn (array $row): bool => $row['pos_outlet_id'] !== null && filled($row['payment_type_id']) && filled($row['store_id'])));
        $outletIds = array_values(array_unique(array_map(fn (array $row): int => (int) $row['pos_outlet_id'], $complete)));

        if (PosOutlet::query()->where('property_id', $property->id)->whereKey($outletIds)->count() !== count($outletIds)) {
            return self::REFUSAL_OUTLET_INVALID;
        }

        DB::transaction(function () use ($actor, $property, $connection, $complete): void {
            foreach ($complete as $row) {
                $mapping = PosOutletMapping::query()->where('pos_connection_id', $connection->id)->where('external_store_id', $row['store_id'])->first() ?? new PosOutletMapping;
                $mapping->property_id = $property->id;
                $mapping->pos_connection_id = $connection->id;
                $mapping->fill([
                    'pos_outlet_id' => (int) $row['pos_outlet_id'],
                    'external_store_id' => $row['store_id'],
                    'external_payment_type_id' => (string) $row['payment_type_id'],
                    'active' => $row['active'],
                ])->save();
            }

            PosOutletMapping::query()
                ->where('pos_connection_id', $connection->id)
                ->whereNotIn('external_store_id', array_column($complete, 'store_id'))
                ->delete();

            activity()
                ->performedOn($connection)
                ->causedBy($actor)
                ->event('pos.loyverse_mappings_saved')
                ->withProperties(['property_id' => $property->id, 'mappings' => count($complete)])
                ->log('Loyverse mappings saved');
        });

        return null;
    }
}
