<?php

use App\Actions\Staff\SyncPermissions;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Create the reservations.view and reservations.manage permissions and grant them to the default roles.
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
