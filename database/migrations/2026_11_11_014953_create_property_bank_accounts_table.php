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
        Schema::create('property_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('bank_name', 100);
            $table->string('account_name', 150);
            $table->string('account_number', 34);
            $table->string('swift_code', 11)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'account_number']);
            $table->index(['property_id', 'active', 'position']);
        });

        DB::statement("ALTER TABLE property_bank_accounts ADD CONSTRAINT property_bank_accounts_text_check CHECK (bank_name = btrim(bank_name) AND bank_name <> '' AND account_name = btrim(account_name) AND account_name <> '' AND account_number = btrim(account_number) AND account_number <> '')");
        DB::statement('ALTER TABLE property_bank_accounts ADD CONSTRAINT property_bank_accounts_swift_check CHECK (swift_code IS NULL OR (swift_code = upper(swift_code) AND char_length(swift_code) IN (8, 11)))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_bank_accounts');
    }
};
