<?php

use App\Actions\Staff\SyncPermissions;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Create the settings.ota-lists permission and grant it to the Owner role of every property. Owner holds every
     * permission by default, so no other default role gets it.
     */
    public function up(): void
    {
        app(SyncPermissions::class)();
    }

    /**
     * Permission rows stay. They are created from PermissionEnum, so there is nothing to undo.
     */
    public function down(): void {}
};
