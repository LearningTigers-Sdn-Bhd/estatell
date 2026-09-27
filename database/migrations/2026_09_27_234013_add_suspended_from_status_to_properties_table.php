<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('suspended_from_status')->nullable();
        });

        DB::statement("UPDATE properties SET suspended_from_status = CASE WHEN went_live_at IS NOT NULL THEN 'live' ELSE 'onboarding' END WHERE status = 'suspended'");
        DB::statement("ALTER TABLE properties ADD CONSTRAINT properties_suspended_from_status_check CHECK ((status = 'suspended' AND suspended_from_status IN ('onboarding', 'pending_approval', 'live')) OR (status <> 'suspended' AND suspended_from_status IS NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE properties DROP CONSTRAINT properties_suspended_from_status_check');

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('suspended_from_status');
        });
    }
};
