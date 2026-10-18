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
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->string('portal_access')->default('hidden')->after('early_departure_policy');
        });

        DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_portal_access_check CHECK (portal_access IN ('hidden', 'all', 'selected'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE rate_plans DROP CONSTRAINT rate_plans_portal_access_check');

        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn('portal_access');
        });
    }
};
