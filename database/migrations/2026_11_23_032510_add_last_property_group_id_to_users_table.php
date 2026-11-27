<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The group a staff user last worked in (group mode), so sign-in lands back there; opening a property clears it.
 * See docs/03-staff/05-group-mode.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('last_property_group_id')->nullable()->after('last_property_id')->constrained('property_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_property_group_id');
        });
    }
};
