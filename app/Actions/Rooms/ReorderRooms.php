<?php

namespace App\Actions\Rooms;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReorderRooms
{
    /**
     * Set the display order of the property's rooms: the given rooms first, in the given order (group by group,
     * each in its typed order), then the other rooms in their current order. All positions are rewritten in one
     * query.
     *
     * Returns false unless every id is an active room of the property, each listed once.
     *
     * @param  list<int>  $orderedIds
     */
    public function __invoke(Property $property, User $actor, array $orderedIds): bool
    {
        $orderedIds = array_map(intval(...), $orderedIds);

        return DB::transaction(function () use ($property, $actor, $orderedIds): bool {
            $current = $property->rooms()->ordered()->lockForUpdate()->get(['id', 'archived_at']);
            $active = $current->whereNull('archived_at')->pluck('id')->all();

            if (count(array_unique($orderedIds)) !== count($orderedIds) || array_diff($orderedIds, $active) !== []) {
                return false;
            }

            $order = array_merge($orderedIds, array_values(array_diff($current->pluck('id')->all(), $orderedIds)));

            if ($order === []) {
                return true;
            }

            $bindings = [];

            foreach ($order as $index => $id) {
                array_push($bindings, $id, $index + 1);
            }

            DB::update(
                'update rooms set position = (case id '.str_repeat('when ? then ? ', count($order)).'end)::integer, updated_at = ? '
                .'where property_id = ? and id in ('.implode(', ', array_fill(0, count($order), '?')).')',
                [...$bindings, now(), $property->id, ...$order],
            );

            activity()
                ->causedBy($actor)
                ->event('room.reordered')
                ->withProperties(['property_id' => $property->id, 'count' => count($orderedIds)])
                ->log('Rooms reordered');

            return true;
        });
    }
}
