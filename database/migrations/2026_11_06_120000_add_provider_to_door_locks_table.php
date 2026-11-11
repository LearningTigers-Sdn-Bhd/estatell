<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('door_locks', function (Blueprint $table) {
            $table->string('provider', 20)->default('ttlock')->after('room_id');
            $table->string('lock_no', 40)->nullable()->after('ttlock_lock_id');
            $table->unsignedBigInteger('ttlock_lock_id')->nullable()->change();
        });

        DB::statement('CREATE UNIQUE INDEX door_locks_property_lock_no_unique ON door_locks (property_id, lock_no) WHERE lock_no IS NOT NULL');
        DB::statement("ALTER TABLE door_locks ADD CONSTRAINT door_locks_provider_check CHECK (
            (provider = 'ttlock' AND ttlock_lock_id IS NOT NULL AND lock_no IS NULL)
            OR (provider = 'elock' AND lock_no IS NOT NULL AND ttlock_lock_id IS NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE door_locks DROP CONSTRAINT door_locks_provider_check');
        DB::statement('DROP INDEX door_locks_property_lock_no_unique');
        DB::table('door_locks')->where('provider', 'elock')->delete();

        Schema::table('door_locks', function (Blueprint $table) {
            $table->unsignedBigInteger('ttlock_lock_id')->nullable(false)->change();
            $table->dropColumn(['provider', 'lock_no']);
        });
    }
};
