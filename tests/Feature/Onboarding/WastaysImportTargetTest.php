<?php

use App\Models\Property;
use App\Models\User;
use App\Services\Onboarding\Wastays\WastaysImportTarget;

beforeEach(function () {
    $this->bundle = wastaysBundleDir(['guests' => [['id' => '1', 'name' => 'Aisha']]]);
    $this->admin = User::factory()->admin()->create();
});

it('resolves the bundle, an onboarding property with the same prefix and an admin', function () {
    $property = Property::factory()->onboarding()->create(['document_prefix' => 'GEH']);

    $target = WastaysImportTarget::resolve($this->bundle, $property->code, $this->admin->email);

    expect($target->property->is($property))->toBeTrue()
        ->and($target->actor->is($this->admin))->toBeTrue()
        ->and($target->bundle->rows('guests')->first()['name'])->toBe('Aisha');
});

it('refuses a property whose prefix is not the bundle hotel code', function () {
    $property = Property::factory()->onboarding()->create(['document_prefix' => 'ABC']);

    WastaysImportTarget::resolve($this->bundle, $property->code, $this->admin->email);
})->throws(InvalidArgumentException::class, 'The bundle is for GEH');

it('refuses a property that has gone live', function () {
    $property = Property::factory()->live()->create(['document_prefix' => 'GEH']);

    WastaysImportTarget::resolve($this->bundle, $property->code, $this->admin->email);
})->throws(InvalidArgumentException::class, 'already gone live');

it('refuses a user who is not an admin', function () {
    $property = Property::factory()->onboarding()->create(['document_prefix' => 'GEH']);

    WastaysImportTarget::resolve($this->bundle, $property->code, User::factory()->create()->email);
})->throws(InvalidArgumentException::class, 'No admin user');
