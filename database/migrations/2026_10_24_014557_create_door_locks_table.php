<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('door_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('ttlock_lock_id');
            $table->string('name');
            $table->unsignedSmallInteger('battery_percent')->nullable();
            $table->boolean('has_gateway')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'ttlock_lock_id']);
        });

        DB::statement('CREATE UNIQUE INDEX door_locks_property_room_unique ON door_locks (property_id, room_id) WHERE room_id IS NOT NULL');
        DB::statement('ALTER TABLE door_locks ADD CONSTRAINT door_locks_battery_check CHECK (battery_percent IS NULL OR battery_percent BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('door_locks');
    }
};
