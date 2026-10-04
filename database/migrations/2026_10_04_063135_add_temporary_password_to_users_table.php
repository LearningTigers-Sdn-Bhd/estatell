<?php

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
        Schema::table('users', function (Blueprint $table) {
            $table->text('temporary_password')->nullable()->after('password');
            $table->timestamp('temporary_password_expires_at')->nullable()->after('temporary_password');
            $table->boolean('must_change_password')->default(false)->after('temporary_password_expires_at');
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_temporary_password_check CHECK ((temporary_password IS NULL) = (temporary_password_expires_at IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_temporary_password_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['temporary_password', 'temporary_password_expires_at', 'must_change_password']);
        });
    }
};
