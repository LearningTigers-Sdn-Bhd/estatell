<?php

use App\Actions\Staff\SyncPermissions;
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
        Schema::table('roles', function (Blueprint $table) {
            $table->string('default_role')->nullable()->after('guard_name');
            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE roles ADD CONSTRAINT roles_property_id_check CHECK (property_id IS NOT NULL)');
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_default_role_check CHECK (default_role IN ('owner', 'manager', 'front_desk', 'housekeeping', 'accounts'))");
        DB::statement('CREATE UNIQUE INDEX roles_property_id_default_role_unique ON roles (property_id, default_role) WHERE default_role IS NOT NULL');

        app(SyncPermissions::class)();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX roles_property_id_default_role_unique');
        DB::statement('ALTER TABLE roles DROP CONSTRAINT roles_default_role_check');
        DB::statement('ALTER TABLE roles DROP CONSTRAINT roles_property_id_check');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['property_id']);
            $table->dropColumn('default_role');
        });
    }
};
