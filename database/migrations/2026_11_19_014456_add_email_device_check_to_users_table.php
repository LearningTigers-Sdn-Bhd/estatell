<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether signing in on a new device needs a code from the user's email. Off for staff an owner created with a
     * temp password, because their email may be made up. See docs/01-platform/09-new-device-check.md.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_device_check')->default(true)->after('must_change_password');
        });

        DB::table('users')
            ->whereNotNull('property_id')
            ->where(fn ($query) => $query
                ->whereNotNull('temporary_password')
                ->orWhereIn('id', DB::table('activity_log')
                    ->where('event', 'staff.created-with-temp-password')
                    ->where('subject_type', 'App\\Models\\User')
                    ->select('subject_id')))
            ->update(['email_device_check' => false]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_device_check');
        });
    }
};
