<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Native POS PIN sign-in: one PIN per staff user, and the registered tablets that may switch users by PIN.
     * See docs/34-native-pos/01-overview.md.
     */
    public function up(): void
    {
        Schema::create('pos_staff_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pin_hash');
            $table->unsignedSmallInteger('pin_length');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'user_id']);
        });

        DB::statement('ALTER TABLE pos_staff_pins ADD CONSTRAINT pos_staff_pins_length_check CHECK (pin_length BETWEEN 4 AND 6)');

        Schema::create('pos_terminal_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('device_token_hash', 64)->unique();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'pos_outlet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_terminal_devices');
        Schema::dropIfExists('pos_staff_pins');
    }
};
