<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('staff_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('email');
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE staff_invitations ADD CONSTRAINT staff_invitations_email_lowercase_check CHECK (email = lower(email))');
        DB::statement('ALTER TABLE staff_invitations ADD CONSTRAINT staff_invitations_closed_once_check CHECK (accepted_at IS NULL OR revoked_at IS NULL)');
        DB::statement('CREATE UNIQUE INDEX staff_invitations_open_email_unique ON staff_invitations (property_id, email) WHERE accepted_at IS NULL AND revoked_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_invitations');
    }
};
