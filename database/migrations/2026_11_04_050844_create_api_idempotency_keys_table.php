<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_token_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('path');
            $table->unsignedSmallInteger('status');
            $table->text('body');
            $table->timestamps();

            $table->unique(['api_token_id', 'key', 'path']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
