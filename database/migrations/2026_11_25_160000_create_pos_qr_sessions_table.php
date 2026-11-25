<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 QR ordering: one revocable token per table session and one per stay. A table token belongs to an outlet and a
     * table; a room token belongs to the reservation unit and carries the stay PIN, hashed. Failed PIN tries are counted on
     * the row. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
     */
    public function up(): void
    {
        Schema::create('pos_qr_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_outlet_id')->nullable()->constrained('pos_outlets')->restrictOnDelete();
            $table->foreignId('pos_table_id')->nullable()->constrained('pos_tables')->restrictOnDelete();
            $table->foreignId('reservation_unit_id')->nullable()->constrained('reservation_units')->restrictOnDelete();
            $table->string('kind', 10);
            $table->string('token', 40)->unique();
            $table->string('stay_pin_hash', 255)->nullable();
            $table->boolean('single_use')->default(true);
            $table->unsignedSmallInteger('failed_pin_attempts')->default(0);
            $table->unsignedSmallInteger('lock_count')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['property_id', 'kind', 'revoked_at']);
        });

        DB::statement("ALTER TABLE pos_qr_sessions ADD CONSTRAINT pos_qr_sessions_kind_check CHECK (kind IN ('table', 'room'))");
        DB::statement("ALTER TABLE pos_qr_sessions ADD CONSTRAINT pos_qr_sessions_shape_check CHECK ((kind = 'table' AND pos_table_id IS NOT NULL AND pos_outlet_id IS NOT NULL AND reservation_unit_id IS NULL) OR (kind = 'room' AND reservation_unit_id IS NOT NULL AND pos_outlet_id IS NULL AND pos_table_id IS NULL AND stay_pin_hash IS NOT NULL))");
        DB::statement('ALTER TABLE pos_qr_sessions ADD CONSTRAINT pos_qr_sessions_attempts_check CHECK (failed_pin_attempts >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_qr_sessions');
    }
};
