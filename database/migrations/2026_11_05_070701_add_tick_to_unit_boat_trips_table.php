<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_boat_trips', function (Blueprint $table) {
            $table->timestamp('ticked_at')->nullable()->after('note');
            $table->foreignId('ticked_by')->nullable()->after('ticked_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('unit_boat_trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticked_by');
            $table->dropColumn('ticked_at');
        });
    }
};
