<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a guest asks for or complains about during a stay. One table for every type. See docs/25-guest-requests/01-guest-requests.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('source', 20);
            $table->string('details', 1000);
            $table->string('status', 20)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('opened_at');
            $table->date('opened_business_date');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->date('resolved_business_date')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_note', 500)->nullable();
            $table->string('external_reference', 100)->nullable();
            $table->timestamps();

            $table->index(['property_id', 'status']);
            $table->index(['property_id', 'type', 'resolved_business_date']);
        });

        DB::statement("ALTER TABLE guest_requests ADD CONSTRAINT guest_requests_type_check CHECK (type IN ('housekeeping', 'maintenance', 'complaint', 'other'))");
        DB::statement("ALTER TABLE guest_requests ADD CONSTRAINT guest_requests_source_check CHECK (source IN ('in_person', 'phone', 'message', 'concierge'))");
        DB::statement("ALTER TABLE guest_requests ADD CONSTRAINT guest_requests_status_check CHECK (status IN ('open', 'in_progress', 'resolved', 'cancelled'))");
        DB::statement('ALTER TABLE guest_requests ADD CONSTRAINT guest_requests_target_check CHECK (reservation_unit_id IS NOT NULL OR room_id IS NOT NULL)');
        DB::statement("ALTER TABLE guest_requests ADD CONSTRAINT guest_requests_resolved_check CHECK ((status = 'resolved') = (resolved_at IS NOT NULL))");
        DB::statement('ALTER TABLE guest_requests ADD CONSTRAINT guest_requests_resolved_date_check CHECK ((resolved_at IS NULL) = (resolved_business_date IS NULL))');
        DB::statement('CREATE UNIQUE INDEX guest_requests_external_reference_unique ON guest_requests (property_id, external_reference) WHERE external_reference IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_requests');
    }
};
