<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('door_passcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('door_lock_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind');
            $table->string('status');
            $table->text('code')->nullable();
            $table->unsignedBigInteger('ttlock_passcode_id')->nullable();
            $table->string('label');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->text('error')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['property_id', 'status']);
            $table->index('reservation_unit_id');
        });

        DB::statement('ALTER TABLE door_passcodes ADD CONSTRAINT door_passcodes_window_check CHECK (ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('door_passcodes');
    }
};
