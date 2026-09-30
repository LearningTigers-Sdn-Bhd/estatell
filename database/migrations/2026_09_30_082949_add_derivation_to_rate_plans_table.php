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
            $table->foreignId('parent_rate_plan_id')->nullable()->after('single_supplement')->constrained('rate_plans')->restrictOnDelete();
            $table->string('derivation', 10)->nullable()->after('parent_rate_plan_id');
            $table->decimal('derivation_value', 12, 2)->nullable()->after('derivation');

            $table->index('parent_rate_plan_id');
        });

        DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_derivation_check CHECK (derivation IS NULL OR derivation IN ('percent', 'amount'))");
        DB::statement('ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_derivation_complete_check CHECK ((parent_rate_plan_id IS NULL AND derivation IS NULL AND derivation_value IS NULL) OR (parent_rate_plan_id IS NOT NULL AND derivation IS NOT NULL AND derivation_value IS NOT NULL AND parent_rate_plan_id <> id))');
        DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_derivation_percent_check CHECK (derivation IS DISTINCT FROM 'percent' OR derivation_value > -100)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE rate_plans DROP CONSTRAINT rate_plans_derivation_percent_check');
        DB::statement('ALTER TABLE rate_plans DROP CONSTRAINT rate_plans_derivation_complete_check');
        DB::statement('ALTER TABLE rate_plans DROP CONSTRAINT rate_plans_derivation_check');

        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_rate_plan_id');
            $table->dropColumn(['derivation', 'derivation_value']);
        });
    }
};
