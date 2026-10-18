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
            $table->boolean('is_company_user')->default(false)->after('is_admin');
        });

        DB::statement('ALTER TABLE users DROP CONSTRAINT users_one_kind_check');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_one_kind_check CHECK (num_nonnulls(property_id, partner_id) + is_admin::int + is_company_user::int = 1)');

        Schema::create('company_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE company_users ADD CONSTRAINT company_users_status_check CHECK (status IN ('pending', 'active', 'revoked'))");
        DB::statement("ALTER TABLE company_users ADD CONSTRAINT company_users_active_accepted_check CHECK (status <> 'active' OR accepted_at IS NOT NULL)");
        DB::statement("ALTER TABLE company_users ADD CONSTRAINT company_users_revoked_check CHECK (status <> 'revoked' OR revoked_at IS NOT NULL)");
        DB::statement("CREATE UNIQUE INDEX company_users_open_company_unique ON company_users (company_id) WHERE status IN ('pending', 'active')");
        DB::statement("CREATE UNIQUE INDEX company_users_open_user_unique ON company_users (property_id, user_id) WHERE status IN ('pending', 'active')");
        DB::statement('CREATE INDEX company_users_user_id_index ON company_users (user_id)');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('portal_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('portal_enabled')->default(false);
        });

        Schema::dropIfExists('company_users');

        DB::table('users')->where('is_company_user', true)->delete();
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_one_kind_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_company_user');
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_one_kind_check CHECK (num_nonnulls(property_id, partner_id) + is_admin::int = 1)');
    }
};
