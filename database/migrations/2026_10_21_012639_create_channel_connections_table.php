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
        Schema::create('channel_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ota_channel_id')->constrained()->restrictOnDelete();
            $table->string('external_channel_id', 40)->nullable()->unique();
            $table->jsonb('settings');
            $table->string('status', 20);
            $table->timestamp('activated_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['channel_property_id', 'ota_channel_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_connections');
    }
};
