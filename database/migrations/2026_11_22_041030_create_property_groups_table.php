<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A group of properties run by one business (Tanini: Sepilok and Kinabatangan). Staff can be given access to the other
 * properties of their home property's group. Platform admins create groups. See docs/03-staff/04-property-groups.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX property_groups_name_unique ON property_groups (lower(name))');

        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('property_group_id')->nullable()->after('partner_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_group_id');
        });

        Schema::dropIfExists('property_groups');
    }
};
