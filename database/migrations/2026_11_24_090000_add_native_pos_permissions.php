<?php

use App\Actions\Staff\SyncPermissions;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Create the Native POS permissions (pos.sell, pos.shift, pos.void, ...) and grant them to the default roles that include them.
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
