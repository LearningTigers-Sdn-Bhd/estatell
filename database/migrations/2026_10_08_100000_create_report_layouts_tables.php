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
        Schema::create('report_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('report_key');
            $table->string('orientation');
            $table->timestamps();

            $table->unique(['property_id', 'report_key']);
        });

        DB::statement("ALTER TABLE report_layouts ADD CONSTRAINT report_layouts_orientation_check CHECK (orientation IN ('portrait', 'landscape'))");

        Schema::create('report_layout_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_layout_id')->constrained()->cascadeOnDelete();
            $table->string('column_key');
            $table->unsignedSmallInteger('position');
            $table->boolean('is_visible');

            $table->unique(['report_layout_id', 'column_key']);
            $table->unique(['report_layout_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_layout_columns');
        Schema::dropIfExists('report_layouts');
    }
};
