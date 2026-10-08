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
        Schema::table('report_layout_columns', function (Blueprint $table) {
            $table->string('align')->nullable();
        });

        DB::statement("ALTER TABLE report_layout_columns ADD CONSTRAINT report_layout_columns_align_check CHECK (align IS NULL OR align IN ('left', 'center', 'right'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE report_layout_columns DROP CONSTRAINT report_layout_columns_align_check');

        Schema::table('report_layout_columns', function (Blueprint $table) {
            $table->dropColumn('align');
        });
    }
};
