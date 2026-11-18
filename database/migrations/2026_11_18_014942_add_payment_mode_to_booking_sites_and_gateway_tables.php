<?php

use App\Actions\Staff\SyncPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The payment mode of a booking website, and the mode each Curlec payment was made in. Existing sites and payments
 * are Live, so nothing changes for them. A new site starts in Test (`SetUpBookingSite`). Adds the
 * `booking-website.payment-mode` permission. See docs/29-booking-engine/04-payment.md#payment-mode.
 */
return new class extends Migration
{
    private const array TABLES = ['property_booking_sites' => 'payment_mode', 'gateway_payments' => 'mode'];

    public function up(): void
    {
        foreach (self::TABLES as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->string($column)->default('live');
            });

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ('test', 'live'))");
        }

        app(SyncPermissions::class)();
    }

    /**
     * The permission row stays. It is created from PermissionEnum, so there is nothing to undo.
     */
    public function down(): void
    {
        foreach (self::TABLES as $table => $column) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_{$column}_check");

            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropColumn($column);
            });
        }
    }
};
