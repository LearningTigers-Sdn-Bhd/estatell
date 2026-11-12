<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The table style of the property's PDFs. The default keeps today's look for the rows that exist.
     */
    public function up(): void
    {
        Schema::table('print_settings', function (Blueprint $table) {
            $table->string('table_style')->default('minimal');
        });

        DB::statement("ALTER TABLE print_settings ADD CONSTRAINT print_settings_table_style_check CHECK (table_style IN ('minimal', 'lined', 'shaded'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE print_settings DROP CONSTRAINT print_settings_table_style_check');

        Schema::table('print_settings', function (Blueprint $table) {
            $table->dropColumn('table_style');
        });
    }
};
