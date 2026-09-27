<?php

use App\Actions\Onboarding\RegisterPropertyOwner;
use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

test('registering creates the property in onboarding with its owner', function () {
    $owner = app(RegisterPropertyOwner::class)([
        'name' => 'Aina',
        'email' => 'aina@example.com',
        'password' => 'password',
        'property_name' => 'Seaview Guesthouse',
    ]);

    $property = $owner->property;

    expect($property->name)->toBe('Seaview Guesthouse')
        ->and($property->status)->toBe(PropertyStatus::Onboarding)
        ->and($property->onboarding_started_at)->not->toBeNull()
        ->and($property->type)->toBeNull()
        ->and($property->country_code)->toBeNull()
        ->and($owner->fresh()->is_admin)->toBeFalse();
});

test('a failed owner insert rolls back the property', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $propertiesBefore = Property::query()->count();

    expect(fn () => app(RegisterPropertyOwner::class)([
        'name' => 'Aina',
        'email' => 'taken@example.com',
        'password' => 'password',
        'property_name' => 'Seaview Guesthouse',
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect(Property::query()->count())->toBe($propertiesBefore);
});
