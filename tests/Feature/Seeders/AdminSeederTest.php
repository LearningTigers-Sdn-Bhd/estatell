<?php

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Support\Facades\Hash;

function seedAdmin(?string $email, ?string $password): void
{
    config(['seeding.admin_email' => $email, 'seeding.admin_password' => $password]);

    test()->seed(AdminSeeder::class);
}

test('creates the platform admin as a user with is_admin set', function () {
    seedAdmin('owner@estatell.test', 'secret-pass');

    $admin = User::query()->sole();

    expect($admin->email)->toBe('owner@estatell.test')
        ->and($admin->is_admin)->toBeTrue()
        ->and(Hash::check('secret-pass', $admin->password))->toBeTrue();
});

test('skips the admin when the password is not set', function () {
    seedAdmin('owner@estatell.test', null);

    expect(User::query()->count())->toBe(0);
});

test('leaves an existing admin unchanged when run again', function () {
    seedAdmin('owner@estatell.test', 'secret-pass');
    seedAdmin('owner@estatell.test', 'other-pass');

    expect(User::query()->count())->toBe(1)
        ->and(Hash::check('secret-pass', User::query()->sole()->password))->toBeTrue();
});

test('refuses to turn an existing staff user into an admin', function () {
    User::factory()->create(['email' => 'owner@estatell.test']);

    seedAdmin('owner@estatell.test', 'secret-pass');
})->throws(RuntimeException::class, 'already belongs to a staff user');

test('rejects a password shorter than 8 characters', function () {
    seedAdmin('owner@estatell.test', 'short');
})->throws(RuntimeException::class, 'at least 8 characters');

test('rejects an invalid email', function () {
    seedAdmin('not-an-email', 'secret-pass');
})->throws(RuntimeException::class, 'valid ADMIN_SEED_EMAIL');
