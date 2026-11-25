<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 walk-in offline mode: each menu snapshot an outlet's register took, kept 7 days so an offline sale can be priced
     * from the snapshot it used. A sale records the snapshot version it was made from. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
     */
    public function up(): void
    {
        Schema::create('pos_menu_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_outlet_id')->constrained('pos_outlets')->restrictOnDelete();
            $table->string('version', 32);
            $table->jsonb('payload');
            $table->timestamp('issued_at');
            $table->timestamps();
            $table->unique(['pos_outlet_id', 'version']);
            $table->index(['property_id', 'issued_at']);
        });

        Schema::table('pos_orders', function (Blueprint $table) {
            $table->string('snapshot_version', 32)->nullable()->after('synced_late');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $table->dropColumn('snapshot_version');
        });

        Schema::dropIfExists('pos_menu_snapshots');
    }
};
