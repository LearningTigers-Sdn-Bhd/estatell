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
        Schema::create('property_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->unique()->constrained()->restrictOnDelete();
            $table->char('currency', 3)->default('MYR');
            $table->string('time_zone')->default('Asia/Kuala_Lumpur');
            $table->string('default_locale')->default('en');
            $table->time('check_in_time')->default('15:00');
            $table->time('check_out_time')->default('11:00');
            $table->unsignedInteger('arrival_grace_minutes')->default(0);
            $table->boolean('auto_assign_rooms')->default(false);
            $table->time('audit_time')->default('02:00');
            $table->boolean('auto_audit_enabled')->default(false);
            $table->string('missed_arrival_policy')->default('keep_as_late_arrival');
            $table->string('overdue_departure_policy')->default('block');
            $table->string('no_show_charge')->default('none');
            $table->string('registration_card_scope')->default('primary_only');
            $table->boolean('card_required_for_check_in')->default(false);
            $table->unsignedInteger('id_document_retention_days')->nullable();
            $table->decimal('security_deposit_amount', 12, 2)->default(0);
            $table->boolean('allow_support_access')->default(true);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE property_settings ADD CONSTRAINT property_settings_missed_arrival_policy_check CHECK (missed_arrival_policy IN ('mark_no_show', 'keep_as_late_arrival'))");
        DB::statement("ALTER TABLE property_settings ADD CONSTRAINT property_settings_overdue_departure_policy_check CHECK (overdue_departure_policy IN ('extend_one_night', 'block'))");
        DB::statement("ALTER TABLE property_settings ADD CONSTRAINT property_settings_no_show_charge_check CHECK (no_show_charge IN ('first_night', 'full_stay', 'none'))");
        DB::statement("ALTER TABLE property_settings ADD CONSTRAINT property_settings_registration_card_scope_check CHECK (registration_card_scope IN ('primary_only', 'every_adult'))");
        DB::statement('ALTER TABLE property_settings ADD CONSTRAINT property_settings_security_deposit_amount_check CHECK (security_deposit_amount >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_settings');
    }
};
