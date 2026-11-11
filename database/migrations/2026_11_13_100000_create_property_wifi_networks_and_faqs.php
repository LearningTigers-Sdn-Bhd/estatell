<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a property tells its guests on the Concierge page: its Wi-Fi networks (shown to a guest who is in the house) and its
 * frequently asked questions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_wifi_networks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('password')->nullable();
            $table->string('note', 200)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['property_id', 'position']);
        });

        Schema::create('property_faqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('question', 200);
            $table->string('answer', 1000);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['property_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_faqs');
        Schema::dropIfExists('property_wifi_networks');
    }
};
