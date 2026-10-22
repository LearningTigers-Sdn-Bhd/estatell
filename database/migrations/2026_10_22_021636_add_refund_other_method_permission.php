<?php

use App\Actions\Staff\SyncPermissions;
use App\Enums\DefaultRoleEnum;
use App\Enums\PermissionEnum;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Create payment.refund-other-method and grant it to the default roles that include it, then grant payment.refund
     * to the default Front desk roles so they refund a deposit at check-out. A role that already holds it is skipped.
     */
    public function up(): void
    {
        app(SyncPermissions::class)();

        DB::transaction(function (): void {
            $permission = Permission::query()->where('name', PermissionEnum::PaymentRefund->value)->first();

            if ($permission === null) {
                return;
            }

            Role::query()->where('default_role', DefaultRoleEnum::FrontDesk->value)->lazyById()->each(function (Role $role) use ($permission): void {
                $role->permissions()->syncWithoutDetaching([$permission->id]);
            });
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Permission rows and grants stay: an owner may rely on them by now.
     */
    public function down(): void {}
};
