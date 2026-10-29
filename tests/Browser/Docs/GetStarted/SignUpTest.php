<?php

use App\Actions\Onboarding\CreatePropertyForOwner;
use App\Actions\Onboarding\RegisterPropertyOwner;
use App\Data\Onboarding\NewOwnerPropertyData;
use App\Data\Onboarding\NewPropertyOwnerData;
use App\Enums\OwnerSetupMethod;
use App\Enums\PropertyType;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

test('register page', function () {
    $page = $this->open(route('register'))
        ->type('#name', 'Aminah Yusof')
        ->type('#property_name', 'Seaview Lodge')
        ->type('#email', 'aminah@seaviewlodge.my')
        ->type('#password', 'Seaview-Lodge-2026')
        ->type('#password_confirmation', 'Seaview-Lodge-2026');

    $this->shoot($page, 'register', element: 'main > div:last-child');
});

test('register page from a partner link', function () {
    $partner = Partner::factory()->create(['name' => 'Borneo Stays']);

    $page = $this->open(route('join', $partner->signup_code))
        ->waitForText('Joining via Borneo Stays');

    $this->shoot($page, 'register-partner', element: 'main > div:last-child');
});

test('verify email page', function () {
    $owner = app(RegisterPropertyOwner::class)(new NewPropertyOwnerData(
        name: 'Aminah Yusof',
        email: 'aminah@seaviewlodge.my',
        password: 'Seaview-Lodge-2026',
        propertyName: 'Seaview Lodge',
    ));
    $this->actingAs($owner);

    $page = $this->open(route('verification.notice'))
        ->waitForText('Resend verification email');

    $this->shoot($page, 'verify-email', element: 'main > div:last-child');
});

test('owner setup link page', function () {
    Notification::fake();

    $property = app(CreatePropertyForOwner::class)(User::factory()->admin()->create(), new NewOwnerPropertyData(
        propertyName: 'Seaview Lodge',
        type: PropertyType::Homestay,
        ownerName: 'Aminah Yusof',
        ownerEmail: 'aminah@seaviewlodge.my',
        setupMethod: OwnerSetupMethod::EmailLink,
    ));
    $owner = $property->users()->firstOrFail();

    $page = $this->open(URL::temporarySignedRoute('guest.owner-setup.show', now()->addDays(7), ['user' => $owner]))
        ->waitForText('Set password and continue');

    $this->shoot($page, 'owner-setup', element: 'main > div:last-child');
});
