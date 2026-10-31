<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('door_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('door_lock_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('card_number');
            $table->unsignedBigInteger('ttlock_card_id')->nullable();
            $table->string('status');
            $table->string('label');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->text('error')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['property_id', 'card_number']);
            $table->index('reservation_unit_id');
        });

        DB::statement('ALTER TABLE door_cards ADD CONSTRAINT door_cards_window_check CHECK (ends_at > starts_at)');
        DB::statement("CREATE UNIQUE INDEX door_cards_live_card_unique ON door_cards (door_lock_id, card_number) WHERE status IN ('pending', 'active')");
    }

    public function down(): void
    {
        Schema::dropIfExists('door_cards');
    }
};
