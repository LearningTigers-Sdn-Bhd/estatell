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
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('full_name', 150);
            $table->string('email', 255)->nullable();
            $table->string('phone', 20)->nullable();
            $table->char('nationality_code', 2)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('preferred_locale', 5)->nullable();
            $table->string('address_line1', 150)->nullable();
            $table->string('address_line2', 150)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->boolean('is_vip')->default(false);
            $table->timestamp('blacklisted_at')->nullable();
            $table->string('blacklist_reason', 500)->nullable();
            $table->timestamp('marketing_consent_at')->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('guests')->restrictOnDelete();
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'full_name']);
            $table->index('email');
            $table->index('phone');
            $table->index('merged_into_id');
        });

        DB::statement("ALTER TABLE guests ADD CONSTRAINT guests_blacklist_check CHECK ((blacklisted_at IS NULL AND blacklist_reason IS NULL) OR (blacklisted_at IS NOT NULL AND btrim(coalesce(blacklist_reason, '')) <> ''))");
        DB::statement('ALTER TABLE guests ADD CONSTRAINT guests_not_merged_into_self_check CHECK (merged_into_id IS NULL OR merged_into_id <> id)');
        DB::statement("ALTER TABLE guests ADD CONSTRAINT guests_gender_check CHECK (gender IS NULL OR gender IN ('male', 'female'))");
        DB::statement("ALTER TABLE guests ADD CONSTRAINT guests_country_codes_check CHECK ((nationality_code IS NULL OR nationality_code ~ '^[A-Z]{2}$') AND (country_code IS NULL OR country_code ~ '^[A-Z]{2}$'))");

        Schema::create('guest_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['guest_id', 'created_at']);
            $table->index('author_id');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->string('name', 50);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX tags_property_name_unique ON tags (property_id, lower(name))');

        Schema::create('guest_tag', function (Blueprint $table) {
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['guest_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guest_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('guest_notes');
        Schema::dropIfExists('guests');
    }
};
