<?php

use App\Actions\Staff\SyncPermissions;
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
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('sell_unit', 10);
            $table->unsignedSmallInteger('max_adults');
            $table->unsignedSmallInteger('max_children')->default(0);
            $table->unsignedSmallInteger('base_occupancy');
            $table->boolean('smoking_allowed')->default(false);
            $table->boolean('pets_allowed')->default(false);
            // No foreign key yet: terms_documents arrives with the registration module.
            $table->unsignedBigInteger('terms_document_id')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'archived_at', 'position']);
        });

        DB::statement("ALTER TABLE room_types ADD CONSTRAINT room_types_sell_unit_check CHECK (sell_unit IN ('room', 'bed'))");
        DB::statement('ALTER TABLE room_types ADD CONSTRAINT room_types_max_adults_check CHECK (max_adults >= 1)');
        DB::statement('ALTER TABLE room_types ADD CONSTRAINT room_types_base_occupancy_check CHECK (base_occupancy >= 1 AND base_occupancy <= max_adults + max_children)');
        DB::statement('CREATE UNIQUE INDEX room_types_active_name_unique ON room_types (property_id, lower(name)) WHERE archived_at IS NULL');

        app(SyncPermissions::class)();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_types');
    }
};
