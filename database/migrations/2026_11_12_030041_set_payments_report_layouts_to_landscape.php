<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Payments report now defaults to landscape. Saved Payments layouts move to landscape too, so every property
     * prints it the same way. Staff can still switch a layout back to portrait.
     */
    public function up(): void
    {
        DB::table('report_layouts')->where('report_key', 'payments')->update(['orientation' => 'landscape', 'updated_at' => now()]);
    }

    /**
     * The earlier orientation of each layout is not kept, so there is nothing to restore.
     */
    public function down(): void {}
};
