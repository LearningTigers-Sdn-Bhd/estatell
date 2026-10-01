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
        Schema::create('guest_identity_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);
            $table->text('number');
            $table->char('issuing_country_code', 2)->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamps();

            $table->index('guest_id');
        });

        DB::statement("ALTER TABLE guest_identity_documents ADD CONSTRAINT guest_identity_documents_type_check CHECK (type IN ('mykad', 'passport', 'other'))");
        DB::statement("ALTER TABLE guest_identity_documents ADD CONSTRAINT guest_identity_documents_country_check CHECK (issuing_country_code IS NULL OR issuing_country_code ~ '^[A-Z]{2}$')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_identity_documents');
    }
};
