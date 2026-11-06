<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. The property sets the terms an agent that pays per booking follows (50% within 72 hours,
     * the rest 30 days before arrival), a company may change them, and a reservation keeps its deadlines.
     */
    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('agent_deposit_percent')->default(50);
            $table->unsignedSmallInteger('agent_deposit_hours')->default(72);
            $table->unsignedSmallInteger('agent_full_payment_days')->default(30);
        });

        DB::statement('ALTER TABLE property_settings ADD CONSTRAINT property_settings_agent_terms_check CHECK (agent_deposit_percent BETWEEN 1 AND 100 AND agent_deposit_hours BETWEEN 1 AND 8760 AND agent_full_payment_days BETWEEN 1 AND 365)');

        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('payment_schedule_enabled')->default(true)->after('pays_collected_taxes');
            $table->unsignedSmallInteger('agent_deposit_percent')->nullable()->after('payment_schedule_enabled');
            $table->unsignedSmallInteger('agent_deposit_hours')->nullable()->after('agent_deposit_percent');
            $table->unsignedSmallInteger('agent_full_payment_days')->nullable()->after('agent_deposit_hours');
        });

        DB::statement('ALTER TABLE companies ADD CONSTRAINT companies_agent_terms_check CHECK ((agent_deposit_percent IS NULL OR agent_deposit_percent BETWEEN 1 AND 100) AND (agent_deposit_hours IS NULL OR agent_deposit_hours BETWEEN 1 AND 8760) AND (agent_full_payment_days IS NULL OR agent_full_payment_days BETWEEN 1 AND 365))');

        Schema::table('reservations', function (Blueprint $table) {
            $table->unsignedSmallInteger('deposit_percent')->nullable();
            $table->timestamp('deposit_due_at')->nullable();
            $table->timestamp('full_payment_due_at')->nullable();
        });

        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_payment_schedule_check CHECK ((deposit_percent IS NULL) = (deposit_due_at IS NULL) AND (full_payment_due_at IS NULL OR deposit_due_at IS NOT NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT reservations_payment_schedule_check');
        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn(['deposit_percent', 'deposit_due_at', 'full_payment_due_at']));
        DB::statement('ALTER TABLE companies DROP CONSTRAINT companies_agent_terms_check');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['payment_schedule_enabled', 'agent_deposit_percent', 'agent_deposit_hours', 'agent_full_payment_days']));
        DB::statement('ALTER TABLE property_settings DROP CONSTRAINT property_settings_agent_terms_check');
        Schema::table('property_settings', fn (Blueprint $table) => $table->dropColumn(['agent_deposit_percent', 'agent_deposit_hours', 'agent_full_payment_days']));
    }
};
