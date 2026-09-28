<?php

use App\Actions\Staff\SyncPermissions;
use App\Enums\PermissionEnum;
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
        Schema::create('property_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('channel');
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['property_id', 'type', 'channel']);
        });

        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('channel');
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['user_id', 'type', 'channel']);
        });

        $types = "'night_audit_completed', 'night_audit_exceptions', 'night_audit_failed', 'registration_card_link', 'onboarding_reminder', 'dorm_strategy_changed', 'support_access_used'";
        $channels = "'mail', 'database'";

        DB::statement("ALTER TABLE property_notification_settings ADD CONSTRAINT property_notification_settings_type_check CHECK (type IN ({$types}))");
        DB::statement("ALTER TABLE property_notification_settings ADD CONSTRAINT property_notification_settings_channel_check CHECK (channel IN ({$channels}))");
        DB::statement("ALTER TABLE user_notification_preferences ADD CONSTRAINT user_notification_preferences_type_check CHECK (type IN ({$types}))");
        DB::statement("ALTER TABLE user_notification_preferences ADD CONSTRAINT user_notification_preferences_channel_check CHECK (channel IN ({$channels}))");

        app(SyncPermissions::class)();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('permissions')->where('name', PermissionEnum::NotificationManage->value)->delete();

        Schema::dropIfExists('user_notification_preferences');
        Schema::dropIfExists('property_notification_settings');
    }
};
