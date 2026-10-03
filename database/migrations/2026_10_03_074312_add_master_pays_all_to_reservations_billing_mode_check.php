<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservations_billing_mode_check');
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_billing_mode_check CHECK (billing_mode IN ('master_pays', 'master_pays_all', 'each_pays'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservations_billing_mode_check');
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_billing_mode_check CHECK (billing_mode IN ('master_pays', 'each_pays'))");
    }
};
