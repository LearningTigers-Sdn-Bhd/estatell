<?php

use App\Actions\Platform\UpdatePartnerSignupMessage;
use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\Property;
use App\Models\User;
use App\Notifications\OwnerSetupNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function newPropertyInput(array $overrides = []): array
{
    return [
        'property_name' => 'Pulau Inn',
        'type' => 'hotel',
        'owner_name' => 'Aisyah Rahim',
        'owner_email' => 'aisyah@pulau.my',
        'setup_method' => 'email_link',
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->partner = Partner::factory()->create(['code_prefix' => 'AS']);
    $this->user = User::factory()->partner($this->partner)->create();
});

test('the Properties page lists only this partner\'s properties with the signup link', function () {
    $mine = Property::factory()->create(['partner_id' => $this->partner->id, 'code' => 'AS1101']);
    Property::factory()->create(['partner_id' => Partner::factory()->create()->id]);
    Property::factory()->create();

    $this->actingAs($this->user)
        ->get(route('partner.properties.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('partner/properties/index')
            ->where('partnerName', $this->partner->name)
            ->where('signupUrl', route('join', $this->partner->signup_code))
            ->where('canCreateProperties', false)
            ->missing('types')
            ->has('properties.data', 1)
            ->where('properties.data.0.code', $mine->code)
            ->where('properties.data.0.status.value', 'live')
            ->has('properties.data.0.createdAt')
        );
});

test('the create form options are sent only when the switch is on', function () {
    $this->partner->update(['can_create_properties' => true]);

    $this->actingAs($this->user)
        ->get(route('partner.properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canCreateProperties', true)
            ->has('types')
            ->has('setupMethods')
            ->has('passwordRules')
        );
});

test('a partner with the switch on creates a property with their prefix', function () {
    Notification::fake();
    $this->partner->update(['can_create_properties' => true]);

    $response = $this->actingAs($this->user)->post(route('partner.properties.store'), newPropertyInput());

    $property = Property::query()->where('name', 'Pulau Inn')->sole();

    $response->assertRedirect(route('partner.properties.show', $property->code));
    expect($property->code)->toBe('AS1101')
        ->and($property->partner_id)->toBe($this->partner->id)
        ->and(User::query()->where('email', 'aisyah@pulau.my')->sole()->property_id)->toBe($property->id)
        ->and(Activity::query()->where('event', 'property.created')->sole()->causer_id)->toBe($this->user->id);
    Notification::assertSentTo(User::query()->where('email', 'aisyah@pulau.my')->sole(), OwnerSetupNotification::class);
});

test('a partner picks the country of the new property and the form lists countries with Malaysia first', function () {
    Notification::fake();
    $this->partner->update(['can_create_properties' => true]);

    $this->actingAs($this->user)
        ->get(route('partner.properties.index'))
        ->assertInertia(fn ($page) => $page->where('countries.0.value', 'MY')->has('countries'));

    $this->post(route('partner.properties.store'), newPropertyInput(['country_code' => 'TH']))->assertRedirect();

    expect(Property::query()->where('name', 'Pulau Inn')->sole()->country_code)->toBe('TH');
});

test('creating a property is a 403 while the switch is off', function () {
    $this->actingAs($this->user)
        ->post(route('partner.properties.store'), newPropertyInput())
        ->assertForbidden();

    expect(Property::query()->count())->toBe(0);
});

test('the create form is checked like the admin one', function () {
    $this->partner->update(['can_create_properties' => true]);

    $this->actingAs($this->user)
        ->post(route('partner.properties.store'), newPropertyInput(['owner_email' => $this->user->email]))
        ->assertSessionHasErrors('owner_email');
});

test('staff and admins cannot create partner properties', function () {
    $this->actingAs(User::factory()->create())->post(route('partner.properties.store'), newPropertyInput())->assertNotFound();
    $this->actingAs(User::factory()->admin()->create())->post(route('partner.properties.store'), newPropertyInput())->assertNotFound();
});

test('a partner opens the tabs of their own property and the server sends only the open tab', function () {
    $property = Property::factory()->create(['partner_id' => $this->partner->id]);
    User::factory()->for($property)->owner()->create(['name' => 'Aisyah Rahim']);

    $this->actingAs($this->user)
        ->get(route('partner.properties.show', $property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('partner/properties/show')
            ->where('tab', 'overview')
            ->where('property.code', $property->code)
            ->where('property.staffCount', 1)
            ->missing('staff')
            ->missing('features')
        );

    $this->get(route('partner.properties.staff', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'staff')
            ->has('staff', 1)
            ->where('staff.0.name', 'Aisyah Rahim')
            ->where('staff.0.role', 'Owner')
            ->missing('features')
        );

    $this->get(route('partner.properties.features', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'features')
            ->has('features')
            ->missing('staff')
        );
});

test('the staff and features tabs expose no write actions', function () {
    $property = Property::factory()->create(['partner_id' => $this->partner->id]);
    User::factory()->for($property)->owner()->unverified()->create();

    $this->actingAs($this->user)
        ->get(route('partner.properties.staff', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('staff.0.canSendSetupLink', false));

    $feature = fn (string $route, array $parameters = []) => route($route, $parameters);

    $this->put($feature('admin.properties.features.update', ['property' => $property->code, 'feature' => 'channel-manager']), ['active' => true])->assertNotFound();
    $this->post($feature('admin.properties.suspend', ['property' => $property->code]))->assertNotFound();
    $this->post($feature('admin.properties.owner.setup-link', ['property' => $property->code]))->assertNotFound();
});

test('another partner\'s property and a direct one are a 404 on every tab', function (string $route) {
    $other = Property::factory()->create(['partner_id' => Partner::factory()->create()->id]);
    $direct = Property::factory()->create();

    $this->actingAs($this->user);

    $this->get(route($route, $other->code))->assertNotFound();
    $this->get(route($route, $direct->code))->assertNotFound();
})->with(['partner.properties.show', 'partner.properties.staff', 'partner.properties.features']);

test('regenerating the link changes the code, is logged and stops the old link', function () {
    $oldCode = $this->partner->signup_code;

    $this->actingAs($this->user)
        ->post(route('partner.link.regenerate'))
        ->assertRedirect(route('partner.properties.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Signup link regenerated. The old link no longer works.']);

    $activity = Activity::query()->sole();

    expect($this->partner->refresh()->signup_code)->not->toBe($oldCode)
        ->and($activity->event)->toBe('partner.signup-link-regenerated')
        ->and($activity->causer_id)->toBe($this->user->id)
        ->and($activity->subject_id)->toBe($this->partner->id);
});

test('staff and admins cannot regenerate a partner link', function () {
    $this->actingAs(User::factory()->create())->post(route('partner.link.regenerate'))->assertNotFound();
    $this->actingAs(User::factory()->admin()->create())->post(route('partner.link.regenerate'))->assertNotFound();
});

test('the Properties page sends the default message template until the partner saves their own', function () {
    $default = "Hi! Set up your property on estatell with {partner}.\nRegister here: {link}\nWe'll help you get started once you've signed up.";

    $this->actingAs($this->user)->get(route('partner.properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('signupMessageTemplate', $default)
            ->where('defaultSignupMessageTemplate', $default)
        );

    $this->partner->update(['signup_message_template' => 'Join {partner}: {link}']);
    $this->user->refresh();

    $this->get(route('partner.properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('signupMessageTemplate', 'Join {partner}: {link}')
            ->where('defaultSignupMessageTemplate', $default)
        );
});

test('saving the message template stores it and is logged', function () {
    $this->actingAs($this->user)
        ->put(route('partner.link.message.update'), ['template' => "Join {partner} here:\n{link}"])
        ->assertRedirect(route('partner.properties.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Message saved.']);

    $activity = Activity::query()->sole();

    expect($this->partner->refresh()->signup_message_template)->toBe("Join {partner} here:\n{link}")
        ->and($activity->event)->toBe('partner.signup-message-updated')
        ->and($activity->causer_id)->toBe($this->user->id)
        ->and($activity->subject_id)->toBe($this->partner->id);
});

test('saving the default wording stores null, and saving the same text again is not logged', function () {
    $this->partner->update(['signup_message_template' => 'Join {partner}: {link}']);

    $this->actingAs($this->user)->put(route('partner.link.message.update'), [
        'template' => "Hi! Set up your property on estatell with {partner}.\r\nRegister here: {link}\r\nWe'll help you get started once you've signed up.",
    ])->assertRedirect();

    expect($this->partner->refresh()->signup_message_template)->toBeNull();

    $this->put(route('partner.link.message.update'), [
        'template' => "Hi! Set up your property on estatell with {partner}.\nRegister here: {link}\nWe'll help you get started once you've signed up.",
    ])->assertRedirect();

    expect(Activity::query()->count())->toBe(1);
});

test('the message template needs {link} and only known placeholders', function (string $template, string $error) {
    $this->actingAs($this->user)
        ->put(route('partner.link.message.update'), ['template' => $template])
        ->assertSessionHasErrors(['template' => $error]);

    expect($this->partner->refresh()->signup_message_template)->toBeNull();
})->with([
    'no link' => ['Join {partner} today', 'Add {link} where the signup link should go.'],
    'unknown placeholder' => ['Hi {name}: {link}', 'Unknown placeholder: {name}. Use {partner} or {link}.'],
]);

test('the message template is required and at most 1000 characters', function () {
    $this->actingAs($this->user);

    $this->put(route('partner.link.message.update'), ['template' => ''])->assertSessionHasErrors('template');
    $this->put(route('partner.link.message.update'), ['template' => '{link}'.str_repeat('a', 1000)])->assertSessionHasErrors('template');
});

test('staff and admins cannot save a partner message template', function () {
    $this->actingAs(User::factory()->create())->put(route('partner.link.message.update'), ['template' => '{link}'])->assertNotFound();
    $this->actingAs(User::factory()->admin()->create())->put(route('partner.link.message.update'), ['template' => '{link}'])->assertNotFound();
});

test('the action refuses a suspended partner and writes nothing', function () {
    $this->partner->update(['status' => PartnerStatus::Suspended, 'suspended_at' => now()]);

    expect(app(UpdatePartnerSignupMessage::class)($this->user, $this->partner, 'Join {partner}: {link}'))->toBeFalse()
        ->and($this->partner->refresh()->signup_message_template)->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
});
