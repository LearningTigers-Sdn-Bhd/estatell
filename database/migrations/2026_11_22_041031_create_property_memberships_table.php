<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which properties a staff user may open: one row per user and property, each deactivated on its own. The role at each
 * property stays in the permission tables (teams keyed on property). `users.property_id` stays as the home property
 * (where the user was invited); every staff user gets a membership there. `users.last_property_id` is the property
 * the user opened last, where sign-in lands. See docs/03-staff/04-property-groups.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'user_id']);
            $table->index(['user_id', 'deactivated_at']);
        });

        DB::statement('INSERT INTO property_memberships (property_id, user_id, deactivated_at, created_at, updated_at) SELECT property_id, id, deactivated_at, created_at, now() FROM users WHERE property_id IS NOT NULL');

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('last_property_id')->nullable()->after('property_id')->constrained('properties')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_property_id');
        });

        Schema::dropIfExists('property_memberships');
    }
};
