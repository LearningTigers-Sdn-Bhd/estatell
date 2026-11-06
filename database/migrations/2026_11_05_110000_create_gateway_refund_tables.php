<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds asked of Curlec, the `payments` rows each one gives back, and the staff notification for a refund that needs
 * attention. See docs/29-booking-engine/04-payment.md#data-model.
 */
return new class extends Migration
{
    private const string OLD_TYPES = "'night_audit_completed', 'night_audit_exceptions', 'night_audit_failed', 'registration_card_link', 'onboarding_reminder', 'dorm_strategy_changed', 'support_access_used', 'go_live_approved', 'go_live_rejected', 'portal_booking_created', 'portal_booking_cancelled', 'portal_cancellation_requested', 'channel_booking_received', 'channel_booking_cancelled', 'channel_removal_blocked', 'nearby_place_removed', 'online_booking_received'";

    private const string NEW_TYPES = self::OLD_TYPES.", 'online_refund_needs_attention'";

    public function up(): void
    {
        Schema::create('gateway_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gateway_payment_id')->constrained()->restrictOnDelete();
            $table->string('gateway_refund_id')->nullable()->unique();
            $table->string('idempotency_key');
            $table->decimal('amount', 12, 2);
            $table->string('status');
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->string('reason')->nullable();
            $table->string('failure_reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('gateway_payload')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'idempotency_key']);
            $table->index(['gateway_payment_id', 'status']);
        });

        DB::statement("ALTER TABLE gateway_refunds ADD CONSTRAINT gateway_refunds_status_check CHECK (status IN ('pending', 'processed', 'failed'))");
        DB::statement('ALTER TABLE gateway_refunds ADD CONSTRAINT gateway_refunds_amount_check CHECK (amount > 0)');

        Schema::create('gateway_refund_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['gateway_refund_id', 'payment_id']);
            $table->index('payment_id');
        });

        DB::statement('ALTER TABLE gateway_refund_parts ADD CONSTRAINT gateway_refund_parts_amount_check CHECK (amount > 0)');

        $this->replaceChecks(self::NEW_TYPES);
    }

    public function down(): void
    {
        $types = ['online_refund_needs_attention'];

        DB::table('property_notification_settings')->whereIn('type', $types)->delete();
        DB::table('user_notification_preferences')->whereIn('type', $types)->delete();

        $this->replaceChecks(self::OLD_TYPES);

        Schema::dropIfExists('gateway_refund_parts');
        Schema::dropIfExists('gateway_refunds');
    }

    private function replaceChecks(string $types): void
    {
        foreach (['property_notification_settings', 'user_notification_preferences'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_type_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_type_check CHECK (type IN ({$types}))");
        }
    }
};
