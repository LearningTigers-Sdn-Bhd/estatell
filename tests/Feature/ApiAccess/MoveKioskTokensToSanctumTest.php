<?php

use App\Models\ApiIdempotencyKey;
use App\Models\Kiosk;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * @param  array<string, mixed>  $row
 */
function legacyKioskRow(Property $property, array $row = []): int
{
    return DB::table('api_tokens')->insertGetId([
        'property_id' => $property->id,
        'kind' => 'kiosk',
        'name' => 'Lobby',
        'scopes' => 'kiosk:*',
        'created_at' => now(),
        'updated_at' => now(),
        ...$row,
    ]);
}

function kioskTokenMigration(): object
{
    return include database_path('migrations/2026_11_04_060600_move_kiosk_tokens_to_sanctum.php');
}

it('keeps a paired kiosk signed in, with the same id and history', function () {
    $migration = kioskTokenMigration();
    $migration->down();
    $property = Property::factory()->create(['name' => 'Palm Resort']);
    $plain = 'est_live_'.str_repeat('a', 40);
    $id = legacyKioskRow($property, ['token_hash' => hash('sha256', $plain), 'paired_at' => now()]);
    DB::table('api_idempotency_keys')->insert(['property_id' => $property->id, 'api_token_id' => $id, 'key' => 'k1', 'path' => 'p', 'status' => 200, 'body' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $activity = Activity::query()->forceCreate(['log_name' => 'default', 'description' => 'Kiosk added', 'subject_type' => 'App\\Models\\ApiToken', 'subject_id' => $id]);

    $migration->up();

    $kiosk = Kiosk::query()->findOrFail($id);
    expect($kiosk->tokens()->sole()->abilities)->toBe(['kiosk:*'])
        ->and(ApiIdempotencyKey::query()->where('kiosk_id', $id)->count())->toBe(1)
        ->and($activity->refresh()->subject_type)->toBe(Kiosk::class);
    $this->getJson('/api/kiosk/v1/me', ['Authorization' => 'Bearer '.$plain])->assertOk()->assertJsonPath('property_name', 'Palm Resort');
});

it('moves no token for a revoked kiosk or one that has not paired', function () {
    $migration = kioskTokenMigration();
    $migration->down();
    $property = Property::factory()->create();
    legacyKioskRow($property, ['token_hash' => hash('sha256', 'old'), 'revoked_at' => now()]);
    legacyKioskRow($property, ['pairing_code_hash' => hash('sha256', 'ABCD2345'), 'pairing_expires_at' => now()->addMinutes(10)]);

    $migration->up();

    expect(Kiosk::query()->count())->toBe(2)
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('stops when a row is not a kiosk', function () {
    $migration = kioskTokenMigration();
    $migration->down();
    legacyKioskRow(Property::factory()->create(), ['kind' => 'integration']);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'not kiosks');
});
