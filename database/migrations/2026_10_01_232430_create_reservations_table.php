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
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('reference', 20);
            $table->string('status', 20)->default('confirmed');
            $table->foreignId('booker_guest_id')->constrained('guests')->restrictOnDelete();
            // No foreign key yet: companies arrives with the city ledger module.
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('group_name', 150)->nullable();
            $table->string('billing_mode', 20)->default('master_pays');
            $table->string('source', 20);
            $table->string('channel_name', 100)->nullable();
            $table->string('external_reference', 100)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('cancellation_requested_at')->nullable();
            $table->foreignId('cancellation_requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['property_id', 'reference']);
            $table->index(['property_id', 'status']);
            $table->index('booker_guest_id');
            $table->index('created_by');
            $table->index('cancellation_requested_by');
        });

        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_status_check CHECK (status IN ('confirmed', 'in_house', 'completed', 'cancelled'))");
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_billing_mode_check CHECK (billing_mode IN ('master_pays', 'each_pays'))");
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_source_check CHECK (source IN ('direct', 'walk_in', 'phone', 'channel', 'agent'))");
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_group_name_check CHECK (group_name IS NULL OR btrim(group_name) <> '')");
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_cancellation_request_check CHECK ((cancellation_requested_at IS NULL) = (cancellation_requested_by IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
