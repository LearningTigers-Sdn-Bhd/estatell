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
        Schema::create('channel_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('external_property_id', 40)->unique();
            $table->string('status', 20);
            $table->timestamp('paused_until')->nullable();
            $table->timestamp('structure_synced_at')->nullable();
            $table->timestamp('changes_sent_at')->nullable();
            $table->timestamp('full_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_properties');
    }
};
