<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Settings → OTA lists is gone, because saved OTA logins are for the Estatell team only. Roles lose the permission
     * through the foreign key cascade.
     */
    public function up(): void
    {
        DB::table('permissions')->where('name', 'settings.ota-lists')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * The permission no longer has a PermissionEnum case, so there is nothing to restore.
     */
    public function down(): void {}
};
