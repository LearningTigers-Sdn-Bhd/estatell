<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The customer-facing display: an outlet's welcome message and static DuitNow QR, and the order a register currently
     * shows (plus whether the cashier has the checkout sheet open). See docs/34-native-pos/10-customer-facing-display.md.
     */
    public function up(): void
    {
        Schema::table('pos_outlets', function (Blueprint $table) {
            $table->string('cfd_welcome_message', 160)->nullable()->after('qr_ignore_stock');
            $table->string('cfd_duitnow_qr_path', 255)->nullable()->after('cfd_welcome_message');
        });

        Schema::table('pos_registers', function (Blueprint $table) {
            $table->foreignId('active_pos_order_id')->nullable()->after('active')->constrained('pos_orders')->nullOnDelete();
            $table->timestamp('active_order_updated_at')->nullable()->after('active_pos_order_id');
            $table->boolean('cfd_checkout_active')->default(false)->after('active_order_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('pos_registers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_pos_order_id');
            $table->dropColumn(['active_order_updated_at', 'cfd_checkout_active']);
        });

        Schema::table('pos_outlets', function (Blueprint $table) {
            $table->dropColumn(['cfd_welcome_message', 'cfd_duitnow_qr_path']);
        });
    }
};
