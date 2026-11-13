<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_delivery_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('attempted_at');

            $table->unique(['webhook_delivery_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_delivery_attempts');
    }
};
