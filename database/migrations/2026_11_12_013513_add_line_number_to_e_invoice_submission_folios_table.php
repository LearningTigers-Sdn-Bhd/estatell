<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The line of the monthly summary that holds each stay, so the summary page reads a stay's amounts from that line of
 * the sent document instead of matching folio numbers in the line text. Null for summaries sent before this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('e_invoice_submission_folios', function (Blueprint $table) {
            $table->unsignedSmallInteger('line_number')->nullable()->after('folio_id');
            $table->unique(['submission_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::table('e_invoice_submission_folios', function (Blueprint $table) {
            $table->dropUnique(['submission_id', 'line_number']);
            $table->dropColumn('line_number');
        });
    }
};
