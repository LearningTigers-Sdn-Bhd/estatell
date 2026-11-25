<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE pos_outlets DROP CONSTRAINT IF EXISTS pos_outlets_charge_item_check');

        Schema::table('pos_outlets', function (Blueprint $table) {
            $table->renameColumn('native_pos_enabled', 'pos_enabled');
        });

        DB::statement('ALTER TABLE pos_outlets ALTER COLUMN pos_enabled SET DEFAULT true');
        DB::statement('ALTER TABLE pos_outlets ADD CONSTRAINT pos_outlets_charge_item_check CHECK (default_charge_item_id IS NOT NULL OR pos_enabled)');

        if (Schema::hasTable('features')) {
            $scopes = DB::table('features')
                ->whereIn('name', ['native-pos', 'pos-integration'])
                ->where('value', 'true')
                ->distinct()
                ->pluck('scope');

            $now = now();

            foreach ($scopes as $scope) {
                DB::table('features')->updateOrInsert(
                    ['name' => 'pos', 'scope' => $scope],
                    ['value' => 'true', 'created_at' => $now, 'updated_at' => $now],
                );
            }

            DB::table('features')->whereIn('name', ['native-pos', 'pos-integration'])->delete();
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pos_outlets DROP CONSTRAINT IF EXISTS pos_outlets_charge_item_check');

        Schema::table('pos_outlets', function (Blueprint $table) {
            $table->renameColumn('pos_enabled', 'native_pos_enabled');
        });

        DB::statement('ALTER TABLE pos_outlets ALTER COLUMN native_pos_enabled SET DEFAULT false');
        DB::statement('ALTER TABLE pos_outlets ADD CONSTRAINT pos_outlets_charge_item_check CHECK (default_charge_item_id IS NOT NULL OR native_pos_enabled)');
    }
};
