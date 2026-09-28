<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Accounts default role becomes Finance, matching the Finance sidebar context.
     * A seeded role keeps its old name when the property already has a custom role called Finance.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE roles DROP CONSTRAINT roles_default_role_check');

        DB::statement("UPDATE roles SET default_role = 'finance' WHERE default_role = 'accounts'");
        DB::statement("UPDATE roles SET name = 'Finance' WHERE default_role = 'finance' AND name = 'Accounts'
            AND NOT EXISTS (SELECT 1 FROM roles other WHERE other.property_id = roles.property_id AND other.name = 'Finance')");

        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_default_role_check CHECK (default_role IN ('owner', 'manager', 'front_desk', 'housekeeping', 'finance'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE roles DROP CONSTRAINT roles_default_role_check');

        DB::statement("UPDATE roles SET default_role = 'accounts' WHERE default_role = 'finance'");
        DB::statement("UPDATE roles SET name = 'Accounts' WHERE default_role = 'accounts' AND name = 'Finance'");

        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_default_role_check CHECK (default_role IN ('owner', 'manager', 'front_desk', 'housekeeping', 'accounts'))");
    }
};
