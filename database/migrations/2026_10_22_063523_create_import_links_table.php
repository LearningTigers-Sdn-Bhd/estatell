<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('source_table');
            $table->string('source_id');
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->unique(['property_id', 'source_table', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_links');
    }
};
