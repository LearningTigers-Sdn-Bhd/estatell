<?php

namespace App\Actions\Staff;

use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BulkCreateStaff
{
    public function __construct(private CreateStaffWithTemporaryPassword $createStaff) {}

    /**
     * Create every row as a staff user with a temp password, all or nothing.
     *
     * The request has already checked every row (email free, role allowed, no duplicates in the batch).
     *
     * @param  list<array{name: string, email: string, role_id: int}>  $rows
     * @return Collection<int, User>
     */
    public function __invoke(Property $property, User $actor, array $rows): Collection
    {
        return DB::transaction(function () use ($property, $actor, $rows): Collection {
            $roles = Role::query()->where('property_id', $property->id)->whereKey(array_column($rows, 'role_id'))->get()->keyBy('id');

            return collect($rows)->map(fn (array $row): User => ($this->createStaff)(
                $property,
                $actor,
                $row['name'],
                $row['email'],
                $roles->get($row['role_id']),
            ));
        });
    }
}
