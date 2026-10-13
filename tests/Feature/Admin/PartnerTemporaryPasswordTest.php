<?php

use App\Models\Partner;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->partner = Partner::factory()->create(['name' => 'Andaman Stays']);
    $this->user = User::factory()->partner($this->partner)->withTemporaryPassword('Teh-0042-Roti')->create(['name' => 'Aina', 'email' => 'aina@andaman.test']);
});

function copiedExport(TestResponse $response, string $extension): string
{
    $copy = sys_get_temp_dir().'/'.uniqid('export-').'.'.$extension;
    copy($response->baseResponse->getFile()->getPathname(), $copy);

    return $copy;
}

test('the message holds the sign-in details of partners with a live temp password', function () {
    $expired = User::factory()->partner()->withTemporaryPassword()->create();
    $expired->forceFill(['temporary_password_expires_at' => now()->subDay()])->save();
    $cleared = User::factory()->partner()->create();

    $response = $this->actingAs($this->admin)
        ->postJson(route('admin.partners.temporary-passwords.message'), ['user_ids' => [$this->user->id, $expired->id, $cleared->id]])
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('skipped', 2);

    expect($response->json('text'))->toContain('aina@andaman.test')
        ->toContain('Teh-0042-Roti')
        ->toContain(route('login'));
});

test('an export gives a CSV headed Partner and logs it without passwords', function () {
    $cleared = User::factory()->partner()->create();

    $response = $this->actingAs($this->admin)
        ->post(route('admin.partners.temporary-passwords.export'), ['user_ids' => [$this->user->id, $cleared->id], 'format' => 'csv'])
        ->assertOk()
        ->assertDownload('temp-passwords-partners.csv')
        ->assertHeader('X-Skipped', '1')
        ->assertHeader('Cache-Control', 'no-store, private');

    $rows = SimpleExcelReader::create(copiedExport($response, 'csv'))->getRows()->all();

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'Partner' => 'Andaman Stays',
            'Name' => 'Aina',
            'Email' => 'aina@andaman.test',
            'Temp password' => 'Teh-0042-Roti',
            'Login URL' => route('login'),
        ])
        ->and($rows[0])->not->toHaveKey('Property');

    $activity = Activity::query()->sole();
    expect($activity->event)->toBe('partner.temp-passwords-exported')
        ->and($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->properties['format'])->toBe('csv')
        ->and($activity->properties['user_ids'])->toBe([$this->user->id])
        ->and(json_encode($activity->properties))->not->toContain('Teh-0042-Roti');
});

test('an export with no usable temp password is refused and not logged', function () {
    $cleared = User::factory()->partner()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.partners.temporary-passwords.export'), ['user_ids' => [$cleared->id], 'format' => 'csv'])
        ->assertSessionHasErrors(['user_ids' => 'None of the chosen partners has an unexpired temp password.']);

    expect(Activity::query()->count())->toBe(0);
});

test('a reset issues a new temp password that must be changed and is logged', function () {
    $this->user->forceFill(['must_change_password' => false])->save();
    $oldPassword = $this->user->password;

    $this->actingAs($this->admin)
        ->post(route('admin.partners.temporary-passwords.reset'), ['user_ids' => [$this->user->id]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'New temp password for 1 partners.']);

    $this->user->refresh();
    $activity = Activity::query()->sole();

    expect($this->user->temporary_password)->not->toBe('Teh-0042-Roti')
        ->and($this->user->password)->not->toBe($oldPassword)
        ->and($this->user->must_change_password)->toBeTrue()
        ->and($this->user->temporary_password_expires_at->isFuture())->toBeTrue()
        ->and($activity->event)->toBe('partner.temp-password-reset')
        ->and($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->subject_id)->toBe($this->partner->id)
        ->and($activity->properties['user_id'])->toBe($this->user->id)
        ->and(json_encode($activity->properties))->not->toContain($this->user->temporary_password);
});

test('a reset skips a suspended partner and the toast counts it', function () {
    $suspendedUser = User::factory()->partner(Partner::factory()->suspended()->create())->withTemporaryPassword('Kopi-1111-Meja')->create();

    $this->actingAs($this->admin)
        ->post(route('admin.partners.temporary-passwords.reset'), ['user_ids' => [$this->user->id, $suspendedUser->id]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'New temp password for 1 partners. 1 skipped.']);

    expect($suspendedUser->refresh()->temporary_password)->toBe('Kopi-1111-Meja')
        ->and(Activity::query()->count())->toBe(1);

    $this->post(route('admin.partners.temporary-passwords.reset'), ['user_ids' => [$suspendedUser->id]])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'No temp password was reset. Suspended partners are skipped.']);

    expect(Activity::query()->count())->toBe(1);
});

test('a staff user id is refused', function () {
    $staff = User::factory()->create();

    foreach (['message', 'reset'] as $name) {
        $this->actingAs($this->admin)
            ->postJson(route("admin.partners.temporary-passwords.{$name}"), ['user_ids' => [$staff->id]])
            ->assertJsonValidationErrors('user_ids.0');
    }

    $this->postJson(route('admin.partners.temporary-passwords.export'), ['user_ids' => [$staff->id], 'format' => 'csv'])
        ->assertJsonValidationErrors('user_ids.0');
});

test('non-admins get a 404 on the message, export and reset routes', function () {
    foreach ([User::factory()->create(), User::factory()->partner($this->partner)->create()] as $user) {
        $this->actingAs($user);

        foreach (['message', 'export', 'reset'] as $name) {
            $this->post(route("admin.partners.temporary-passwords.{$name}"), ['user_ids' => [$this->user->id], 'format' => 'csv'])->assertNotFound();
        }
    }

    expect($this->user->refresh()->temporary_password)->toBe('Teh-0042-Roti')
        ->and(Activity::query()->count())->toBe(0);
});
