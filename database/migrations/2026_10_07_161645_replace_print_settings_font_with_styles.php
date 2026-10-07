<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The font becomes a style. Standard and Chinese both become Office: Chinese text now falls back to Noto Sans SC
     * in every style.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE print_settings DROP CONSTRAINT print_settings_font_check');
        DB::table('print_settings')->update(['font' => 'office']);
        DB::statement("ALTER TABLE print_settings ALTER COLUMN font SET DEFAULT 'office'");
        DB::statement("ALTER TABLE print_settings ADD CONSTRAINT print_settings_font_check CHECK (font IN ('office', 'modern', 'fancy', 'handwritten'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE print_settings DROP CONSTRAINT print_settings_font_check');
        DB::table('print_settings')->update(['font' => 'standard']);
        DB::statement("ALTER TABLE print_settings ALTER COLUMN font SET DEFAULT 'standard'");
        DB::statement("ALTER TABLE print_settings ADD CONSTRAINT print_settings_font_check CHECK (font IN ('standard', 'chinese'))");
    }
};
