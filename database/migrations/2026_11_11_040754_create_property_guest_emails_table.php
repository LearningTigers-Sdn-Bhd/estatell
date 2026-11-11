<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A property's own wording for a guest email (`GuestEmail`). No row means the default text.
     */
    public function up(): void
    {
        Schema::create('property_guest_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('email', 40);
            $table->string('subject', 200);
            $table->text('body');
            $table->timestamps();

            $table->unique(['property_id', 'email']);
        });

        DB::statement("ALTER TABLE property_guest_emails ADD CONSTRAINT property_guest_emails_email_check CHECK (email IN ('e_invoice'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('property_guest_emails');
    }
};
