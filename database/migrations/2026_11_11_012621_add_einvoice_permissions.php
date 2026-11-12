<?php

use App\Actions\Staff\SyncPermissions;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Create einvoice.view and einvoice.manage and grant them to the default roles that include them (Owner, Manager
     * and Finance). `SyncPermissions` grants only permissions it creates, so a role an owner changed is left alone.
     */
    public function up(): void
    {
        app(SyncPermissions::class)();
    }

    /**
     * Permission rows and grants stay: an owner may rely on them by now.
     */
    public function down(): void {}
};
