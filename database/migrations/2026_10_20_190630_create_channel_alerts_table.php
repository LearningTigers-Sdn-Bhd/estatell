<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('channel_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_property_id')->constrained()->cascadeOnDelete();
            $table->string('event', 30);
            $table->string('external_booking_id', 40)->nullable();
            $table->string('revision_id', 40)->nullable();
            $table->jsonb('payload');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['channel_property_id', 'resolved_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_alerts');
    }
};
