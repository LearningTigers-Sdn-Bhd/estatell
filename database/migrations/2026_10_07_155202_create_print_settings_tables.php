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
        Schema::create('print_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->unique()->constrained()->restrictOnDelete();
            $table->string('font')->default('standard');
            $table->string('font_size')->default('medium');
            $table->boolean('show_logo')->default(true);
            $table->boolean('show_address')->default(true);
            $table->boolean('show_contact')->default(true);
            $table->boolean('show_registration_number')->default(true);
            $table->boolean('show_tin')->default(false);
            $table->string('footer_text', 500)->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE print_settings ADD CONSTRAINT print_settings_font_check CHECK (font IN ('standard', 'chinese'))");
        DB::statement("ALTER TABLE print_settings ADD CONSTRAINT print_settings_font_size_check CHECK (font_size IN ('small', 'medium', 'large'))");
        DB::statement("ALTER TABLE print_settings ADD CONSTRAINT print_settings_footer_text_check CHECK (footer_text IS NULL OR (footer_text = btrim(footer_text) AND footer_text <> ''))");

        Schema::create('print_setting_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('print_setting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_id')->constrained()->restrictOnDelete();

            $table->unique(['print_setting_id', 'tax_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('print_setting_taxes');
        Schema::dropIfExists('print_settings');
    }
};
