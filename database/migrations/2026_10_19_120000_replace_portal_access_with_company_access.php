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
            $table->string('company_access')->default('everyone')->after('early_departure_policy');
            $table->boolean('company_at_desk')->default(true)->after('company_access');
            $table->boolean('company_in_portal')->default(true)->after('company_at_desk');
        });

        Schema::table('company_rate_plans', function (Blueprint $table) {
            $table->boolean('at_desk')->default(true)->after('rate_plan_id');
            $table->boolean('in_portal')->default(false)->after('at_desk');
        });

        DB::statement("UPDATE company_rate_plans SET in_portal = (SELECT portal_access = 'selected' FROM rate_plans WHERE rate_plans.id = company_rate_plans.rate_plan_id)");
        DB::statement("UPDATE rate_plans SET company_access = 'selected' WHERE EXISTS (SELECT 1 FROM company_rate_plans WHERE company_rate_plans.rate_plan_id = rate_plans.id)");
        DB::statement("UPDATE rate_plans SET company_access = 'all', company_at_desk = false, company_in_portal = true WHERE company_access = 'everyone' AND portal_access = 'all'");

        DB::statement('ALTER TABLE rate_plans DROP CONSTRAINT rate_plans_portal_access_check');

        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn('portal_access');
        });

        DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_company_access_check CHECK (company_access IN ('everyone', 'all', 'selected'))");
        DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_company_switch_check CHECK (company_access <> 'all' OR company_at_desk OR company_in_portal)");
        DB::statement('ALTER TABLE company_rate_plans ADD CONSTRAINT company_rate_plans_switch_check CHECK (at_desk OR in_portal)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->string('portal_access')->default('hidden')->after('early_departure_policy');
        });

        DB::statement("UPDATE rate_plans SET portal_access = 'all' WHERE company_access = 'all' AND company_in_portal");
        DB::statement("UPDATE rate_plans SET portal_access = 'selected' WHERE company_access = 'selected' AND EXISTS (SELECT 1 FROM company_rate_plans WHERE company_rate_plans.rate_plan_id = rate_plans.id AND company_rate_plans.in_portal)");

        DB::statement('ALTER TABLE company_rate_plans DROP CONSTRAINT company_rate_plans_switch_check');
        DB::statement('ALTER TABLE rate_plans DROP CONSTRAINT rate_plans_company_switch_check');
        DB::statement('ALTER TABLE rate_plans DROP CONSTRAINT rate_plans_company_access_check');

        Schema::table('company_rate_plans', function (Blueprint $table) {
            $table->dropColumn(['at_desk', 'in_portal']);
        });

        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn(['company_access', 'company_at_desk', 'company_in_portal']);
        });

        DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_portal_access_check CHECK (portal_access IN ('hidden', 'all', 'selected'))");
    }
};
