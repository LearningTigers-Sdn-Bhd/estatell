<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `api_tokens` becomes `kiosks`, and the tokens move to Sanctum's `personal_access_tokens`. Ids stay the same, so the
 * idempotency keys and the activity log keep pointing at the right kiosk. A paired kiosk keeps working: Sanctum finds a
 * token without an `id|` part by its hash. See docs/28-api-access/01-api-tokens.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('api_tokens')->where('kind', '!=', 'kiosk')->exists()) {
            throw new RuntimeException('api_tokens has rows that are not kiosks. Move them by hand before this migration.');
        }

        DB::table('api_tokens')
            ->whereNotNull('token_hash')
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get()
            ->each(fn (object $row) => DB::table('personal_access_tokens')->insert([
                'tokenable_type' => 'App\\Models\\Kiosk',
                'tokenable_id' => $row->id,
                'name' => $row->name,
                'token' => $row->token_hash,
                'abilities' => json_encode(explode(' ', $row->scopes)),
                'expires_at' => $row->expires_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]));

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropIndex(['property_id', 'kind']);
            $table->dropUnique(['token_hash']);
            $table->dropColumn(['kind', 'token_hash', 'scopes', 'expires_at']);
            $table->renameIndex('api_tokens_pairing_code_hash_unique', 'kiosks_pairing_code_hash_unique');
            $table->index('property_id', 'kiosks_property_id_index');
        });

        Schema::rename('api_tokens', 'kiosks');

        Schema::table('api_idempotency_keys', function (Blueprint $table) {
            $table->renameColumn('api_token_id', 'kiosk_id');
            $table->renameIndex('api_idempotency_keys_api_token_id_key_path_unique', 'api_idempotency_keys_kiosk_id_key_path_unique');
        });

        DB::table('activity_log')->where('subject_type', 'App\\Models\\ApiToken')->update(['subject_type' => 'App\\Models\\Kiosk']);
    }

    public function down(): void
    {
        DB::table('activity_log')->where('subject_type', 'App\\Models\\Kiosk')->update(['subject_type' => 'App\\Models\\ApiToken']);

        Schema::table('api_idempotency_keys', function (Blueprint $table) {
            $table->renameIndex('api_idempotency_keys_kiosk_id_key_path_unique', 'api_idempotency_keys_api_token_id_key_path_unique');
            $table->renameColumn('kiosk_id', 'api_token_id');
        });

        Schema::rename('kiosks', 'api_tokens');

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropIndex('kiosks_property_id_index');
            $table->renameIndex('kiosks_pairing_code_hash_unique', 'api_tokens_pairing_code_hash_unique');
            $table->string('kind')->default('kiosk');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->string('scopes')->default('kiosk:*');
            $table->timestamp('expires_at')->nullable();
            $table->index(['property_id', 'kind']);
        });

        DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\Kiosk')
            ->orderBy('id')
            ->get()
            ->each(fn (object $row) => DB::table('api_tokens')->where('id', $row->tokenable_id)->update([
                'token_hash' => $row->token,
                'scopes' => implode(' ', json_decode($row->abilities, true)),
                'expires_at' => $row->expires_at,
            ]));

        DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\Kiosk')->delete();
    }
};
