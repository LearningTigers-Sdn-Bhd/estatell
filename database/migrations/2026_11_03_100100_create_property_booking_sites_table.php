<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_booking_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mode');
            // The foreign key to site_templates comes with the template registry.
            $table->unsignedBigInteger('site_template_id')->nullable();
            $table->string('main_color', 7)->nullable();
            $table->text('about_text')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_booking_sites');
    }
};
