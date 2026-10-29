<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyport_devices', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('platform')->nullable();
            $table->string('agent_version')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('simulator')->default(false);
            $table->boolean('client_code_present')->default(false);
            $table->jsonb('reader_state')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('property_id');
        });

        Schema::create('keyport_pairing_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('device_id')->nullable()->constrained('keyport_devices')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('keyport_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('keyport_devices')->nullOnDelete();
            $table->string('type');
            $table->string('status')->default('pending');
            $table->jsonb('payload');
            $table->jsonb('result')->nullable();
            $table->string('serial')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('idempotency_key')->unique();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'status', 'expires_at']);
        });

        DB::statement("ALTER TABLE keyport_jobs ADD CONSTRAINT keyport_jobs_type_check CHECK (type IN ('guest_card.issue', 'guest_card.rewrite', 'guest_card.erase', 'guest_card.read'))");
        DB::statement("ALTER TABLE keyport_jobs ADD CONSTRAINT keyport_jobs_status_check CHECK (status IN ('pending', 'claimed', 'succeeded', 'failed', 'uncertain', 'cancelled', 'expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('keyport_jobs');
        Schema::dropIfExists('keyport_pairing_codes');
        Schema::dropIfExists('keyport_devices');
    }
};
