<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('the admin gate allows admins and denies staff', function () {
    expect(Gate::forUser(User::factory()->admin()->create())->allows('admin'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('admin'))->toBeFalse();
});

test('only admins can view horizon', function () {
    expect(Gate::forUser(User::factory()->admin()->create())->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});

test('is_admin cannot be mass assigned', function () {
    $user = User::create([
        'name' => 'Aisha',
        'email' => 'aisha@example.com',
        'password' => 'password',
        'is_admin' => true,
    ]);

    expect($user->fresh()->is_admin)->toBeFalse();
});
