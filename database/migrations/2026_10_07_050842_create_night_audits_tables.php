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
        Schema::create('night_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('business_date_id')->unique()->constrained()->restrictOnDelete();
            $table->string('trigger', 20);
            $table->string('status', 20);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('forced')->default(false);
            $table->text('force_reason')->nullable();
            $table->timestamps();

            $table->index('property_id');
            $table->index('run_by');
        });

        DB::statement("ALTER TABLE night_audits ADD CONSTRAINT night_audits_trigger_check CHECK (trigger IN ('scheduled', 'manual'))");
        DB::statement("ALTER TABLE night_audits ADD CONSTRAINT night_audits_status_check CHECK (status IN ('running', 'completed', 'failed'))");
        DB::statement("ALTER TABLE night_audits ADD CONSTRAINT night_audits_completed_check CHECK ((status = 'completed') = (completed_at IS NOT NULL))");
        DB::statement('ALTER TABLE night_audits ADD CONSTRAINT night_audits_force_reason_check CHECK (forced = false OR force_reason IS NOT NULL)');

        Schema::create('night_audit_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('night_audit_id')->constrained()->cascadeOnDelete();
            $table->string('step', 40);
            $table->string('status', 20);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['night_audit_id', 'step']);
            $table->index('property_id');
        });

        DB::statement("ALTER TABLE night_audit_steps ADD CONSTRAINT night_audit_steps_step_check CHECK (step IN ('claim_business_date', 'resolve_missed_arrivals', 'resolve_overdue_departures', 'post_room_charges', 'post_scheduled_add_ons', 'update_room_statuses', 'close_and_open_next'))");
        DB::statement("ALTER TABLE night_audit_steps ADD CONSTRAINT night_audit_steps_status_check CHECK (status IN ('running', 'completed', 'failed'))");

        Schema::create('night_audit_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('night_audit_id')->constrained()->cascadeOnDelete();
            $table->string('step', 40);
            $table->foreignId('reservation_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->text('message');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'resolved_at']);
            $table->index('night_audit_id');
            $table->index('reservation_unit_id');
            $table->index('resolved_by');
        });

        DB::statement("ALTER TABLE night_audit_exceptions ADD CONSTRAINT night_audit_exceptions_step_check CHECK (step IN ('resolve_missed_arrivals', 'resolve_overdue_departures', 'post_room_charges', 'post_scheduled_add_ons', 'update_room_statuses', 'close_and_open_next'))");
        DB::statement("ALTER TABLE night_audit_exceptions ADD CONSTRAINT night_audit_exceptions_type_check CHECK (type IN ('missed_arrival', 'overdue_departure', 'room_charge', 'add_on', 'unexpected_error'))");
        DB::statement('ALTER TABLE night_audit_exceptions ADD CONSTRAINT night_audit_exceptions_resolved_check CHECK (resolved_by IS NULL OR resolved_at IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('night_audit_exceptions');
        Schema::dropIfExists('night_audit_steps');
        Schema::dropIfExists('night_audits');
    }
};
