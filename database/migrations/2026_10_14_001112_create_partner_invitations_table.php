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
        Schema::create('partner_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE partner_invitations ADD CONSTRAINT partner_invitations_email_lowercase_check CHECK (email = lower(email))');
        DB::statement('ALTER TABLE partner_invitations ADD CONSTRAINT partner_invitations_closed_once_check CHECK (accepted_at IS NULL OR revoked_at IS NULL)');
        DB::statement('CREATE UNIQUE INDEX partner_invitations_open_partner_unique ON partner_invitations (partner_id) WHERE accepted_at IS NULL AND revoked_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX partner_invitations_open_email_unique ON partner_invitations (email) WHERE accepted_at IS NULL AND revoked_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partner_invitations');
    }
};
