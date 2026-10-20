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
        Schema::create('channel_booking_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_property_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('channel_connection_id')->nullable();
            $table->string('external_booking_id', 40);
            $table->string('revision_id', 40)->unique();
            $table->string('kind', 20);
            $table->jsonb('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['channel_property_id', 'external_booking_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_booking_revisions');
    }
};
