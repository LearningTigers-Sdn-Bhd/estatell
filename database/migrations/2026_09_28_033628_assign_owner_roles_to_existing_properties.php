<?php

use App\Actions\Staff\AssignOwnerRole;
use App\Actions\Staff\SeedDefaultRoles;
use App\Models\Property;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Seed default roles for properties created before roles existed and make each one's first user the Owner.
     */
    public function up(): void
    {
        Property::query()->whereDoesntHave('roles')->lazyById()->each(function (Property $property): void {
            app(SeedDefaultRoles::class)($property);

            $firstUser = $property->users()->oldest('id')->first();

            if ($firstUser !== null) {
                app(AssignOwnerRole::class)($property, $firstUser);
            }
        });
    }

    /**
     * Roles and assignments are data now; rolling back the schema migration removes them.
     */
    public function down(): void
    {
        //
    }
};
