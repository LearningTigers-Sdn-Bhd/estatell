<?php

use App\Actions\Staff\SyncPermissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Create payouts.view and grant it to the Owner role of every property. Owner holds every permission by default, so
     * no other default role gets it.
     */
    public function up(): void
    {
        app(SyncPermissions::class)();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Permission rows and grants stay: an owner may rely on them by now.
     */
    public function down(): void {}
};
