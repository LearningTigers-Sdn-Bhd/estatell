<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per user and browser that passed the new-device check. A browser holds one device token in a cookie, so a
     * shared front desk PC can be trusted for several staff. Only the token's SHA-256 hash is stored.
     */
    public function up(): void
    {
        Schema::create('user_trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->string('device_name', 100)->nullable();
            $table->timestamp('last_used_at');
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['user_id', 'token_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_trusted_devices');
    }
};
