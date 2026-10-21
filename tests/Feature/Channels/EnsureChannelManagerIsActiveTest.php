<?php

use App\Features\ChannelManager;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'property.access', 'channels'])
        ->get('/p/{property:code}/channels-probe', fn () => 'ok');
});

test('channel routes return 404 while the feature is off', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get("/p/{$owner->property->code}/channels-probe")->assertNotFound();
});

test('channel routes work once the feature is on', function () {
    $owner = User::factory()->owner()->create();
    Feature::for($owner->property)->activate(ChannelManager::class);

    $this->actingAs($owner)->get("/p/{$owner->property->code}/channels-probe")->assertOk();
});
