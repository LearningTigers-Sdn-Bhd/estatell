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
        Schema::table('properties', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('contact_phone');
        });

        Schema::create('property_licences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('issuer', 150);
            $table->string('number', 50);
            $table->date('expires_on')->nullable();
            $table->boolean('show_on_documents')->default(false);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->index(['property_id', 'position']);
        });

        DB::statement("ALTER TABLE property_licences ADD CONSTRAINT property_licences_text_check CHECK (btrim(issuer) <> '' AND btrim(number) <> '')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_licences');

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('legal_name');
        });
    }
};
