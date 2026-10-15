<?php

use App\Actions\Property\SeedPropertyDefaults;
use App\Enums\DefaultRoleEnum;
use App\Enums\OnboardingStep;
use App\Enums\PropertyType;
use App\Enums\StepState;
use App\Models\OnboardingSkip;
use App\Models\Property;
use App\Models\Role;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Services\Onboarding\Checks\TeamCheck;
use App\Services\Property\SettingsLock;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/OnboardingSetup.php';

beforeEach(function () {
    $this->owner = User::factory()->owner()->for(Property::factory()->onboarding())->create();
    $this->property = $this->owner->property;
    completeOnboardingBasics($this->property);
    app(SeedPropertyDefaults::class)($this->property, PropertyType::Hotel, 'MY');
    $this->role = fn (DefaultRoleEnum $role): Role => Role::query()->where('property_id', $this->property->id)->where('default_role', $role)->firstOrFail();
    $this->save = fn (array $rows, ?User $user = null) => $this->actingAs($user ?? $this->owner)
        ->put(route('onboarding.team.update', $this->property->code), ['rows' => $rows]);
});

test('the page sends the owner, the saved staff and the four roles with their descriptions', function () {
    User::factory()->for($this->property)->withRole(DefaultRoleEnum::Finance)->create(['name' => 'Siti']);

    $this->actingAs($this->owner)
        ->get(route('onboarding.team.edit', $this->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/team')
            ->where('owner.email', $this->owner->email)
            ->has('staff', 1)
            ->where('staff.0.name', 'Siti')
            ->where('staff.0.roleName', 'Finance')
            ->has('roles', 4)
            ->where('roles.0.label', 'Manager')
            ->where('roles.3.label', 'Finance')
            ->where('roles.1.description', DefaultRoleEnum::FrontDesk->description())
            ->where('defaultRoleId', (string) ($this->role)(DefaultRoleEnum::FrontDesk)->id)
            ->where('locked', false));
});

test('a save creates staff with temp passwords and goes to the next section', function () {
    ($this->save)([
        ['name' => 'Aina Rahman', 'email' => 'Aina@Seaview.my', 'role_id' => ($this->role)(DefaultRoleEnum::FrontDesk)->id],
    ])->assertRedirect(route('onboarding.room-types.edit', $this->property->code));

    $aina = User::query()->where('email', 'aina@seaview.my')->firstOrFail();

    expect($aina->property_id)->toBe($this->property->id)
        ->and($aina->must_change_password)->toBeTrue()
        ->and($aina->temporary_password)->not->toBeNull()
        ->and($aina->roles->pluck('name')->all())->toBe(['Front desk']);
});

test('empty rows are dropped and a save with no rows is valid', function () {
    $before = User::query()->count();

    ($this->save)([['name' => '', 'email' => '', 'role_id' => '']])->assertSessionHasNoErrors();

    expect(User::query()->count())->toBe($before);
});

test('rows are checked one by one', function () {
    User::factory()->create(['email' => 'taken@seaview.my']);
    StaffInvitation::factory()->for($this->property)->create(['email' => 'invited@seaview.my']);
    $frontDesk = ($this->role)(DefaultRoleEnum::FrontDesk)->id;

    ($this->save)([
        ['name' => '', 'email' => 'a@seaview.my', 'role_id' => $frontDesk],
        ['name' => 'B', 'email' => 'not-an-email', 'role_id' => $frontDesk],
        ['name' => 'C', 'email' => 'taken@seaview.my', 'role_id' => $frontDesk],
        ['name' => 'D', 'email' => 'invited@seaview.my', 'role_id' => $frontDesk],
        ['name' => 'E', 'email' => 'e@seaview.my', 'role_id' => ''],
        ['name' => 'F', 'email' => 'e@seaview.my', 'role_id' => $frontDesk],
    ])->assertSessionHasErrors(['rows.0.name', 'rows.1.email', 'rows.2.email', 'rows.3.email', 'rows.4.role_id', 'rows.5.email']);
});

test('the owner role is refused and nothing is written when one row fails', function () {
    $before = User::query()->count();

    ($this->save)([
        ['name' => 'Good', 'email' => 'good@seaview.my', 'role_id' => ($this->role)(DefaultRoleEnum::Manager)->id],
        ['name' => 'Boss', 'email' => 'boss@seaview.my', 'role_id' => ($this->role)(DefaultRoleEnum::Owner)->id],
    ])->assertSessionHasErrors('rows.1.role_id');

    expect(User::query()->count())->toBe($before);
});

test('saving people removes a skip', function () {
    OnboardingSkip::query()->create(['property_id' => $this->property->id, 'step' => OnboardingStep::Team, 'skipped_at' => now()]);

    ($this->save)([['name' => 'Aina', 'email' => 'aina@seaview.my', 'role_id' => ($this->role)(DefaultRoleEnum::Manager)->id]]);

    expect(OnboardingSkip::query()->where('property_id', $this->property->id)->where('step', OnboardingStep::Team)->exists())->toBeFalse();
});

test('the check is done with a non-owner and not started with only the owner', function () {
    $check = new TeamCheck;

    expect($check->state($this->property))->toBe(StepState::NotStarted);

    $staff = User::factory()->for($this->property)->withRole(DefaultRoleEnum::Housekeeping)->create();

    expect($check->state($this->property))->toBe(StepState::Done);

    $staff->forceFill(['deactivated_at' => now()])->save();

    expect($check->state($this->property))->toBe(StepState::NotStarted);
});

test('adding staff does not lock the country', function () {
    User::factory()->for($this->property)->withRole(DefaultRoleEnum::Housekeeping)->create();

    expect(app(SettingsLock::class)->setupStarted($this->property))->toBeFalse();
});

test('the step is locked until Basics is done and a save is refused', function () {
    $property = Property::factory()->onboarding()->create();
    $owner = User::factory()->owner()->for($property)->create();

    $this->actingAs($owner)
        ->get(route('onboarding.team.edit', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('locked', true)->where('blockedBy.label', OnboardingStep::Basics->label()));

    $this->actingAs($owner)
        ->put(route('onboarding.team.update', $property->code), ['rows' => []])
        ->assertSessionHasErrors('onboarding');
});

test('import reads a file into rows and nothing is saved', function () {
    $before = User::query()->count();
    $file = UploadedFile::fake()->createWithContent('team.csv', "name,email,role\nAina,aina@seaview.my,front desk\nBob,bob@seaview.my,Owner\n");

    $this->actingAs($this->owner)
        ->postJson(route('onboarding.team.import', $this->property->code), ['file' => $file])
        ->assertOk()
        ->assertJsonPath('rows.0.roleId', ($this->role)(DefaultRoleEnum::FrontDesk)->id)
        ->assertJsonPath('rows.1.roleId', null)
        ->assertJsonPath('rows.1.problem', 'Unknown role "Owner".');

    expect(User::query()->count())->toBe($before);
});

test('the template downloads', function () {
    $this->actingAs($this->owner)
        ->get(route('onboarding.team.template', [$this->property->code, 'csv']))
        ->assertOk();
});

test('another property code returns 404', function () {
    $other = Property::factory()->onboarding()->create();

    $this->actingAs($this->owner)
        ->get(route('onboarding.team.edit', $other->code))
        ->assertNotFound();
});
