<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->string('print_label', 12)->nullable()->after('name');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->string('print_label', 12)->nullable()->after('number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('print_label');
        });

        Schema::table('room_types', function (Blueprint $table) {
            $table->dropColumn('print_label');
        });
    }
};
