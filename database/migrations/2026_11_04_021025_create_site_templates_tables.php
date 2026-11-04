<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('type');
            $table->foreignId('property_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status');
            $table->boolean('allows_main_color')->default(false);
            $table->string('preview_url')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE site_templates ADD CONSTRAINT site_templates_type_property_check CHECK ((type = 'custom' AND property_id IS NOT NULL) OR (type = 'standard' AND property_id IS NULL))");

        Schema::create('site_template_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_template_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->boolean('required')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['site_template_id', 'key']);
        });

        Schema::table('property_booking_sites', function (Blueprint $table) {
            $table->foreign('site_template_id')->references('id')->on('site_templates')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('property_booking_sites', function (Blueprint $table) {
            $table->dropForeign(['site_template_id']);
        });

        Schema::dropIfExists('site_template_slots');
        Schema::dropIfExists('site_templates');
    }
};
