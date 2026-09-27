<?php

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Support\Facades\Hash;

function seedAdmin(?string $email, ?string $password): void
{
    config(['seeding.admin_email' => $email, 'seeding.admin_password' => $password]);

    test()->seed(AdminSeeder::class);
}

test('creates the platform admin in the admins table', function () {
    seedAdmin('owner@estatell.test', 'secret-pass');

    $admin = Admin::query()->sole();

    expect($admin->email)->toBe('owner@estatell.test')
        ->and(Hash::check('secret-pass', $admin->password))->toBeTrue()
        ->and(User::query()->where('email', 'owner@estatell.test')->exists())->toBeFalse();
});

test('skips the admin when the password is not set', function () {
    seedAdmin('owner@estatell.test', null);

    expect(Admin::query()->count())->toBe(0);
});

test('leaves an existing admin unchanged when run again', function () {
    seedAdmin('owner@estatell.test', 'secret-pass');
    seedAdmin('owner@estatell.test', 'other-pass');

    expect(Admin::query()->count())->toBe(1)
        ->and(Hash::check('secret-pass', Admin::query()->sole()->password))->toBeTrue();
});

test('rejects a password shorter than 8 characters', function () {
    seedAdmin('owner@estatell.test', 'short');
})->throws(RuntimeException::class, 'at least 8 characters');

test('rejects an invalid email', function () {
    seedAdmin('not-an-email', 'secret-pass');
})->throws(RuntimeException::class, 'valid ADMIN_SEED_EMAIL');
