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
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->char('code_prefix', 2)->unique();
            $table->char('signup_code', 6)->unique();
            $table->string('status')->default('active');
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE partners ADD CONSTRAINT partners_code_prefix_check CHECK (code_prefix ~ '^[A-Z]{2}$' AND code_prefix <> 'ES')");
        DB::statement("ALTER TABLE partners ADD CONSTRAINT partners_signup_code_check CHECK (signup_code ~ '^[A-HJ-NP-Z2-9]{6}$')");
        DB::statement("ALTER TABLE partners ADD CONSTRAINT partners_status_check CHECK (status IN ('invited', 'active', 'suspended'))");

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->after('property_id')->constrained()->restrictOnDelete();
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->constrained()->restrictOnDelete();
        });

        DB::statement('ALTER TABLE users DROP CONSTRAINT users_property_or_admin_check');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_one_kind_check CHECK (num_nonnulls(property_id, partner_id) + is_admin::int = 1)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_one_kind_check');

        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
        });

        DB::table('users')->whereNotNull('partner_id')->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_property_or_admin_check CHECK ((is_admin AND property_id IS NULL) OR (NOT is_admin AND property_id IS NOT NULL))');

        Schema::dropIfExists('partners');
    }
};
