<?php

use App\Actions\Staff\SyncPermissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Create front-desk.view and grant it to the default roles that include it (Owner, Manager and Front desk).
     * `SyncPermissions` grants only permissions it creates, so a role an owner changed is left alone.
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
