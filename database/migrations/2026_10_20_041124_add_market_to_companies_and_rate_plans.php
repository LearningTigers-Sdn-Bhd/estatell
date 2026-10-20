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
        Schema::table('companies', function (Blueprint $table) {
            $table->string('market')->nullable()->after('type');
        });

        Schema::table('rate_plans', function (Blueprint $table) {
            $table->string('company_market')->default('all')->after('company_in_portal');
        });

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_market_check CHECK (market IS NULL OR market IN ('local', 'international'))");
        DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_company_market_check CHECK (company_market IN ('all', 'local', 'international'))");
        DB::statement("ALTER TABLE rate_plans ADD CONSTRAINT rate_plans_company_market_access_check CHECK (company_market = 'all' OR company_access <> 'everyone')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE rate_plans DROP CONSTRAINT rate_plans_company_market_access_check');
        DB::statement('ALTER TABLE rate_plans DROP CONSTRAINT rate_plans_company_market_check');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_market_check');

        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn('company_market');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('market');
        });
    }
};
