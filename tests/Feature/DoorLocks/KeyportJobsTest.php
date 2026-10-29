<?php

use App\Enums\KeyportJobStatus;
use App\Enums\KeyportJobType;
use App\Models\KeyportDevice;
use App\Models\KeyportJob;
use App\Models\Property;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../Support/KeyportSetup.php';

function keyportClaim(string $token, array $health = []): TestResponse
{
    return test()->postJson(route('keyport.jobs.claim'), $health, keyportHeaders($token));
}

it('refuses a missing, wrong or revoked token', function () {
    ['device' => $device, 'token' => $token] = keyportSetup();

    $this->postJson(route('keyport.jobs.claim'), [], ['X-Keyport-Version' => '1'])->assertUnauthorized();
    keyportClaim('kp_live_wrong')->assertUnauthorized()->assertJsonPath('error.code', 'unauthorized');

    $device->forceFill(['revoked_at' => now()])->save();
    keyportClaim($token)->assertUnauthorized();
});

it('refuses a property without Door locks', function () {
    ['token' => $token] = keyportSetup(feature: false);

    keyportClaim($token)->assertForbidden()->assertJsonPath('error.code', 'door_locks_disabled');
});

it('answers 204 when there is no work and records the heartbeat', function () {
    ['token' => $token, 'device' => $device] = keyportSetup();

    keyportClaim($token, [
        'agent_version' => '0.2.0',
        'simulator' => false,
        'readers' => [['kind' => 'rfrw', 'state' => 'connected']],
        'client_code_present' => true,
        'busy' => false,
    ])->assertNoContent();

    $device->refresh();
    expect($device->last_seen_at->toIso8601String())->toBe(now()->toIso8601String())
        ->and($device->agent_version)->toBe('0.2.0')
        ->and($device->client_code_present)->toBeTrue()
        ->and($device->reader_state)->toBe([['kind' => 'rfrw', 'state' => 'connected']]);
});

it('hands over a job in property time and starts its lease', function () {
    ['token' => $token, 'property' => $property, 'device' => $device] = keyportSetup();
    $job = KeyportJob::factory()->for($property)->create();

    keyportClaim($token)->assertOk()
        ->assertJsonPath('id', $job->public_id)
        ->assertJsonPath('type', 'guest_card.issue')
        ->assertJsonPath('expires_at', '2026-10-14 20:10:00')
        ->assertJsonPath('payload.lock_no', '1.1.101')
        ->assertJsonPath('payload.display.room', '101');

    $job->refresh();
    expect($job->status)->toBe(KeyportJobStatus::Claimed)
        ->and($job->device_id)->toBe($device->id)
        ->and($job->lease_until->toIso8601String())->toBe(now()->addSeconds(120)->toIso8601String());
});

it('gives a job to one device only', function () {
    ['token' => $token, 'property' => $property] = keyportSetup();
    $secondToken = 'kp_live_second';
    KeyportDevice::factory()->for($property)->withToken($secondToken)->create();
    KeyportJob::factory()->for($property)->create();

    keyportClaim($token)->assertOk();
    keyportClaim($secondToken)->assertNoContent();
});

it('does not hand out a job while the agent is busy', function () {
    ['token' => $token, 'property' => $property] = keyportSetup();
    $job = KeyportJob::factory()->for($property)->create();

    keyportClaim($token, ['busy' => true])->assertNoContent();

    expect($job->refresh()->status)->toBe(KeyportJobStatus::Pending);
});

it('never hands out another property job', function () {
    ['token' => $token] = keyportSetup();
    $other = KeyportJob::factory()->for(Property::factory())->create();

    keyportClaim($token)->assertNoContent();

    expect($other->refresh()->status)->toBe(KeyportJobStatus::Pending);
});

it('expires a pending job and never offers it', function () {
    ['token' => $token, 'property' => $property] = keyportSetup();
    $job = KeyportJob::factory()->for($property)->create(['expires_at' => now()->subMinute()]);

    keyportClaim($token)->assertNoContent();

    expect($job->refresh()->status)->toBe(KeyportJobStatus::Expired)->and($job->error_code)->toBe('job_expired');
});

it('marks a lapsed card write uncertain and never offers it again', function () {
    ['token' => $token, 'device' => $device] = keyportSetup();
    $job = KeyportJob::factory()->claimedBy($device)->create(['lease_until' => now()->subSecond()]);

    keyportClaim($token)->assertNoContent();
    keyportClaim($token)->assertNoContent();

    expect($job->refresh()->status)->toBe(KeyportJobStatus::Uncertain)->and($job->error_code)->toBe('lease_expired');
});

it('marks a lapsed card read failed', function () {
    ['token' => $token, 'device' => $device] = keyportSetup();
    $job = KeyportJob::factory()->claimedBy($device)->ofType(KeyportJobType::Read)->create(['lease_until' => now()->subSecond()]);

    keyportClaim($token)->assertNoContent();

    expect($job->refresh()->status)->toBe(KeyportJobStatus::Failed);
});

it('saves a result and accepts the same result again', function () {
    ['token' => $token, 'device' => $device] = keyportSetup();
    $job = KeyportJob::factory()->claimedBy($device)->create();
    $body = ['status' => 'succeeded', 'serial' => 'A1B2C3D4', 'card' => ['lock_no' => '1.1.101', 'check_out' => '2026-10-16 12:00:00', 'flags' => 0]];

    $this->postJson(route('keyport.jobs.result', $job->public_id), $body, keyportHeaders($token))->assertNoContent();
    $this->postJson(route('keyport.jobs.result', $job->public_id), $body, keyportHeaders($token))->assertNoContent();

    $job->refresh();
    expect($job->status)->toBe(KeyportJobStatus::Succeeded)
        ->and($job->serial)->toBe('A1B2C3D4')
        ->and($job->finished_at)->not->toBeNull()
        ->and($job->result['card']['lock_no'])->toBe('1.1.101');
});

it('refuses a different result for a finished job', function () {
    ['token' => $token, 'device' => $device] = keyportSetup();
    $job = KeyportJob::factory()->claimedBy($device)->create();

    $this->postJson(route('keyport.jobs.result', $job->public_id), ['status' => 'succeeded', 'serial' => 'A1B2C3D4'], keyportHeaders($token))->assertNoContent();
    $this->postJson(route('keyport.jobs.result', $job->public_id), ['status' => 'failed', 'error_code' => 'write_failed'], keyportHeaders($token))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'job_already_finished');

    expect($job->refresh()->status)->toBe(KeyportJobStatus::Succeeded);
});

it('refuses a late success after the lease made the job uncertain', function () {
    ['token' => $token, 'device' => $device] = keyportSetup();
    $job = KeyportJob::factory()->claimedBy($device)->create(['lease_until' => now()->subSecond()]);

    keyportClaim($token)->assertNoContent();
    $this->postJson(route('keyport.jobs.result', $job->public_id), ['status' => 'succeeded', 'serial' => 'A1B2C3D4'], keyportHeaders($token))
        ->assertStatus(409);

    expect($job->refresh()->status)->toBe(KeyportJobStatus::Uncertain);
});

it('does not take a result for another device job or an unknown job', function () {
    ['token' => $token, 'property' => $property] = keyportSetup();
    $other = KeyportDevice::factory()->for($property)->create();
    $job = KeyportJob::factory()->claimedBy($other)->create();

    $this->postJson(route('keyport.jobs.result', $job->public_id), ['status' => 'succeeded'], keyportHeaders($token))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'job_not_found');
    $this->postJson(route('keyport.jobs.result', 'job_unknown'), ['status' => 'succeeded'], keyportHeaders($token))->assertNotFound();

    expect($job->refresh()->status)->toBe(KeyportJobStatus::Claimed);
});

it('refuses a status an agent may not report', function () {
    ['token' => $token, 'device' => $device] = keyportSetup();
    $job = KeyportJob::factory()->claimedBy($device)->create();

    $this->postJson(route('keyport.jobs.result', $job->public_id), ['status' => 'pending'], keyportHeaders($token))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_request');
});
