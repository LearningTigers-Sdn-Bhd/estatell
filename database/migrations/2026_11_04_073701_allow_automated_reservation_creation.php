<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE reservations ALTER COLUMN created_by DROP NOT NULL');
    }

    public function down(): void
    {
        if (DB::table('reservations')->whereNull('created_by')->exists()) {
            throw new RuntimeException('Automated reservations exist. Keep this migration and use a forward fix.');
        }
        DB::statement('ALTER TABLE reservations ALTER COLUMN created_by SET NOT NULL');
    }
};
