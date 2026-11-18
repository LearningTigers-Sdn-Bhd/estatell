<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A question that is off stays in the staff list but guests do not see it. Existing questions stay on.
     */
    public function up(): void
    {
        Schema::table('property_faqs', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('property_faqs', function (Blueprint $table) {
            $table->dropColumn('active');
        });
    }
};
