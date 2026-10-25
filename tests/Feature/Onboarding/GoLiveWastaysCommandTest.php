<?php

use App\Enums\PropertyStatus;
use App\Models\BusinessDate;
use App\Models\PlatformSetting;
use App\Models\Property;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\PendingCommand;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    PlatformSetting::query()->whereKey(1)->update(['require_go_live_approval' => false]);
    Cache::forget(PlatformSettings::CACHE_KEY);

    $this->admin = User::factory()->admin()->create();
    $this->bundle = wastaysBundleDir(['guests' => [['id' => '1']]]);
});

function goLiveWastays(array $options = []): PendingCommand
{
    return test()->artisan('wastays:go-live', [
        'bundle' => test()->bundle,
        'property' => test()->property->code,
        '--actor' => test()->admin->email,
        ...$options,
    ]);
}

it('takes a ready property live from the first wastays business date', function () {
    $this->property = Property::factory()->onboarding()->create(['document_prefix' => 'GEH']);
    completeRequiredOnboardingSteps($this->property);

    goLiveWastays()->expectsOutputToContain('is live from 2026-08-13')->assertSuccessful();

    expect($this->property->refresh()->status)->toBe(PropertyStatus::Live)
        ->and(BusinessDate::query()->where('property_id', $this->property->id)->value('date')->toDateString())->toBe('2026-08-13');
});

it('changes nothing on a dry run', function () {
    $this->property = Property::factory()->onboarding()->create(['document_prefix' => 'GEH']);
    completeRequiredOnboardingSteps($this->property);

    goLiveWastays(['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();

    expect($this->property->refresh()->status)->toBe(PropertyStatus::Onboarding);
});

it('lists the steps that are not done and stays in setup', function () {
    $this->property = Property::factory()->onboarding()->create(['document_prefix' => 'GEH']);

    goLiveWastays()->expectsOutputToContain('not ready to go live')->assertFailed();

    expect($this->property->refresh()->status)->toBe(PropertyStatus::Onboarding);
});

it('refuses a property that is already live and one with another prefix', function () {
    $this->property = Property::factory()->live()->create(['document_prefix' => 'GEH']);
    goLiveWastays()->expectsOutputToContain('already gone live')->assertFailed();

    $this->property = Property::factory()->onboarding()->create(['document_prefix' => 'ABC']);
    goLiveWastays()->expectsOutputToContain('The bundle is for GEH')->assertFailed();
});
