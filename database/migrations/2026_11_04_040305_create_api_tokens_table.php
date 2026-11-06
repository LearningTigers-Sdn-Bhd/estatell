<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('name');
            $table->string('device_name')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->string('pairing_code_hash', 64)->nullable()->unique();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->string('scopes');
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
