<?php

use App\Actions\Onboarding\CreatePropertyForOwner;
use App\Actions\Property\GenerateDocumentPrefix;
use App\Actions\Property\IssuePropertyCode;
use App\Actions\Property\SeedPropertyDefaults;
use App\Actions\Staff\AssignOwnerRole;
use App\Data\Onboarding\NewOwnerPropertyData;
use App\Enums\DefaultRoleEnum;
use App\Enums\OwnerSetupMethod;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Models\Property;
use App\Models\User;
use App\Notifications\OwnerSetupNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
});

test('both setup methods create the property owner defaults and expected notification', function (OwnerSetupMethod $method) {
    $input = new NewOwnerPropertyData(propertyName: 'Seaview Guesthouse', type: PropertyType::Hotel, ownerName: 'Aina Rahman', ownerEmail: ' AINA@Example.com ', setupMethod: $method, password: 'initial-password');

    $property = app(CreatePropertyForOwner::class)($this->admin, $input);
    $owner = User::query()->where('email', 'aina@example.com')->sole();
    $activity = Activity::query()->sole();

    expect($property->name)->toBe('Seaview Guesthouse')
        ->and($property->type)->toBe(PropertyType::Hotel)
        ->and($property->status)->toBe(PropertyStatus::Onboarding)
        ->and($property->country_code)->toBe('MY')
        ->and($property->onboarding_started_at)->not->toBeNull()
        ->and($property->roles()->count())->toBe(5)
        ->and($property->settings()->count())->toBe(1)
        ->and($owner->name)->toBe('Aina Rahman')
        ->and($owner->property_id)->toBe($property->id)
        ->and($owner->email_verified_at)->toBeNull();

    setPermissionsTeamId($property->id);
    expect($owner->hasRole(DefaultRoleEnum::Owner->label()))->toBeTrue()
        ->and($activity->event)->toBe('property.created')
        ->and($activity->causer->is($this->admin))->toBeTrue()
        ->and($activity->subject->is($property))->toBeTrue()
        ->and($activity->properties->all())->toMatchArray([
            'type' => 'hotel',
            'country' => 'MY',
            'owner_email' => 'aina@example.com',
            'setup_method' => $method->value,
        ])
        ->and($activity->properties->has('password'))->toBeFalse()
        ->and(json_encode($activity->properties->all()))->not->toContain('initial-password');

    if ($method === OwnerSetupMethod::EmailLink) {
        Notification::assertSentTo($owner, OwnerSetupNotification::class);
        Notification::assertNotSentTo($owner, VerifyEmail::class);
    } else {
        expect(Hash::check('initial-password', $owner->password))->toBeTrue();
        Notification::assertSentTo($owner, VerifyEmail::class);
        Notification::assertNotSentTo($owner, OwnerSetupNotification::class);
    }
})->with(OwnerSetupMethod::cases());

test('a failure while seeding rolls back the property owner and activity', function () {
    $seedPropertyDefaults = Mockery::mock(SeedPropertyDefaults::class);
    $seedPropertyDefaults->shouldReceive('__invoke')->once()->andThrow(new RuntimeException('Seeding failed.'));
    $assignOwnerRole = Mockery::mock(AssignOwnerRole::class);
    $action = new CreatePropertyForOwner($seedPropertyDefaults, app(GenerateDocumentPrefix::class), app(IssuePropertyCode::class), $assignOwnerRole);
    $propertiesBefore = Property::query()->count();
    $usersBefore = User::query()->count();

    expect(fn () => $action($this->admin, new NewOwnerPropertyData(propertyName: 'Seaview Guesthouse', type: PropertyType::Hotel, ownerName: 'Aina Rahman', ownerEmail: 'aina@example.com', setupMethod: OwnerSetupMethod::EmailLink)))->toThrow(RuntimeException::class, 'Seeding failed.');

    expect(Property::query()->count())->toBe($propertiesBefore)
        ->and(User::query()->count())->toBe($usersBefore)
        ->and(Activity::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('an initial password can create an already verified owner without sending verification mail', function () {
    Event::fake([Verified::class]);

    $property = app(CreatePropertyForOwner::class)($this->admin, new NewOwnerPropertyData(propertyName: 'Seaview Guesthouse', type: PropertyType::Hotel, ownerName: 'Aina Rahman', ownerEmail: 'aina@example.com', setupMethod: OwnerSetupMethod::InitialPassword, password: 'initial-password', verifyOwnerEmail: true));

    $owner = User::query()->where('email', 'aina@example.com')->sole();

    expect($owner->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('initial-password', $owner->password))->toBeTrue()
        ->and(Activity::query()->where('event', 'property.created')->value('properties')->get('owner_email_verified'))->toBeTrue()
        ->and(Activity::query()->where('event', 'owner.email-verified')->value('properties')->all())->toMatchArray([
            'property_id' => $property->id,
            'email' => 'aina@example.com',
        ]);

    Event::assertDispatched(Verified::class, fn (Verified $event) => $event->user->is($owner));
    Notification::assertNothingSent();
});

test('the create endpoint validates input and creates nothing on failure', function (array $changes, string $error) {
    config(['estatell.support_require_two_factor' => false]);
    User::factory()->create(['email' => 'TAKEN@EXAMPLE.COM']);
    $propertiesBefore = Property::query()->count();
    $usersBefore = User::query()->count();

    $this->actingAs($this->admin)
        ->post(route('admin.properties.store'), [
            'property_name' => 'Seaview Guesthouse',
            'type' => PropertyType::Hotel->value,
            'owner_name' => 'Aina Rahman',
            'owner_email' => 'new@example.com',
            'setup_method' => OwnerSetupMethod::EmailLink->value,
            ...$changes,
        ])
        ->assertSessionHasErrors($error);

    expect(Property::query()->count())->toBe($propertiesBefore)
        ->and(User::query()->count())->toBe($usersBefore)
        ->and(Activity::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'duplicate normalized email' => [['owner_email' => ' TAKEN@EXAMPLE.COM '], 'owner_email'],
    'invalid property type' => [['type' => 'castle'], 'type'],
    'invalid setup method' => [['setup_method' => 'sms'], 'setup_method'],
    'email link cannot pre-verify the owner' => [['verify_owner_email' => true], 'verify_owner_email'],
    'missing initial password' => [['setup_method' => OwnerSetupMethod::InitialPassword->value], 'password'],
    'unconfirmed initial password' => [[
        'setup_method' => OwnerSetupMethod::InitialPassword->value,
        'password' => 'password',
        'password_confirmation' => 'different',
    ], 'password'],
]);

test('the create endpoint redirects to the new property and exposes form options', function () {
    config(['estatell.support_require_two_factor' => false]);

    $this->actingAs($this->admin)
        ->get(route('admin.properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('types', 5)
            ->has('setupMethods', 2)
            ->where('setupMethods.0.value', 'email_link')
            ->has('passwordRules'));

    $response = $this->post(route('admin.properties.store'), [
        'property_name' => 'Seaview Guesthouse',
        'type' => PropertyType::Hotel->value,
        'owner_name' => 'Aina Rahman',
        'owner_email' => ' AINA@Example.com ',
        'setup_method' => OwnerSetupMethod::EmailLink->value,
    ]);

    $property = Property::query()->sole();
    $response->assertRedirect(route('admin.properties.show', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Property and owner created.']);
});

test('the create endpoint enforces guest staff and two-factor boundaries', function () {
    config(['estatell.support_require_two_factor' => true]);
    $payload = [
        'property_name' => 'Seaview Guesthouse',
        'type' => PropertyType::Hotel->value,
        'owner_name' => 'Aina Rahman',
        'owner_email' => 'aina@example.com',
        'setup_method' => OwnerSetupMethod::EmailLink->value,
    ];

    $this->post(route('admin.properties.store'), $payload)->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->post(route('admin.properties.store'), $payload)->assertNotFound();
    $this->actingAs($this->admin)->post(route('admin.properties.store'), $payload)
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);

    expect(Property::query()->where('name', 'Seaview Guesthouse')->count())->toBe(0);
});

test('owner setup mail escapes owner and property names', function () {
    $property = Property::factory()->create(['name' => '<script>Property</script>']);
    $owner = User::factory()->for($property)->unverified()->owner()->create(['name' => '<script>Owner</script>']);

    $html = (string) (new OwnerSetupNotification)->toMail($owner)->render();

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;');
});
