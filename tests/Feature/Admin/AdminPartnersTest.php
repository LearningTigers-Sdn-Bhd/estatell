<?php

use App\Actions\Platform\CreatePartner;
use App\Actions\Property\IssuePropertyCode;
use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\PartnerInvitation;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->input = [
        'partner_name' => 'Andaman Stays',
        'code_prefix' => 'as',
        'name' => 'Aina Rahman',
        'email' => 'Ops@Andaman.test',
    ];
});

test('a user cannot be both staff and a partner user', function () {
    $partner = Partner::factory()->create();
    $staff = User::factory()->create();

    expect(fn () => DB::table('users')->where('id', $staff->id)->update(['partner_id' => $partner->id]))
        ->toThrow(QueryException::class, 'users_one_kind_check');
});

test('the database refuses the ES prefix and a bad signup code for a partner', function () {
    expect(fn () => app(CreatePartner::class)('Direct', 'ES', PartnerStatus::Active))
        ->toThrow(QueryException::class, 'partners_code_prefix_check');
});

test('add with a temp password creates the partner, its counter and its login', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.partners.store'), $this->input)
        ->assertRedirect(route('admin.partners.index'))
        ->assertInertiaFlash('partnerAddedId', User::query()->where('email', 'ops@andaman.test')->sole()->id)
        ->assertInertiaFlashMissing('partnerSignInMessage');

    $partner = Partner::query()->sole();
    $user = User::query()->where('partner_id', $partner->id)->sole();

    expect($partner->status)->toBe(PartnerStatus::Active)
        ->and($partner->code_prefix)->toBe('AS')
        ->and($partner->signup_code)->toMatch('/^[A-HJ-NP-Z2-9]{6}$/')
        ->and(DB::table('property_code_counters')->where('prefix', 'AS')->value('last_number'))->toBe(1100)
        ->and($user->email)->toBe('ops@andaman.test')
        ->and($user->property_id)->toBeNull()
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->temporary_password)->not->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull();

    DB::transaction(fn () => expect(app(IssuePropertyCode::class)('AS'))->toBe('AS1101'));

    $this->assertDatabaseHas('activity_log', ['event' => 'partner.created-with-temp-password', 'causer_id' => $this->admin->id, 'subject_id' => $partner->id]);
});

test('the new partner can sign in with the temp password', function () {
    $this->actingAs($this->admin)->post(route('admin.partners.store'), $this->input);
    $temporaryPassword = User::query()->where('email', 'ops@andaman.test')->sole()->temporary_password;
    auth()->logout();

    $this->post(route('login.store'), ['email' => 'ops@andaman.test', 'password' => $temporaryPassword])
        ->assertRedirect(route('partner.properties.index'));
});

test('validation refuses a bad, reserved or taken prefix and an email in use', function (array $changes, string $field) {
    Partner::factory()->create(['code_prefix' => 'KP']);
    User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs($this->admin)
        ->post(route('admin.partners.store'), [...$this->input, ...$changes])
        ->assertSessionHasErrors($field);

    expect(Partner::query()->where('name', 'Andaman Stays')->exists())->toBeFalse();
})->with([
    'reserved ES' => [['code_prefix' => 'ES'], 'code_prefix'],
    'not letters' => [['code_prefix' => 'A1'], 'code_prefix'],
    'too long' => [['code_prefix' => 'ASA'], 'code_prefix'],
    'taken by a partner' => [['code_prefix' => 'kp'], 'code_prefix'],
    'email in use' => [['email' => 'Taken@Example.test'], 'email'],
]);

test('the Active tab lists partners with email, property count and temp password', function () {
    $this->actingAs($this->admin)->post(route('admin.partners.store'), $this->input);
    $partner = Partner::query()->sole();
    Property::factory()->count(2)->create(['partner_id' => $partner->id]);
    Partner::factory()->suspended()->create();

    $this->actingAs($this->admin)
        ->get(route('admin.partners.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/partners/index')
            ->has('partners.data', 1)
            ->where('partners.data.0.name', 'Andaman Stays')
            ->where('partners.data.0.codePrefix', 'AS')
            ->where('partners.data.0.userId', User::query()->where('email', 'ops@andaman.test')->sole()->id)
            ->where('partners.data.0.email', 'ops@andaman.test')
            ->where('partners.data.0.propertiesCount', 2)
            ->where('partners.data.0.status.value', 'active')
            ->whereNot('partners.data.0.temporaryPasswordExpiresAt', null)
        );
});

test('staff and guests cannot use the admin partner routes', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)->get(route('admin.partners.index'))->assertNotFound();
    $this->post(route('admin.partners.store'), $this->input)->assertNotFound();
    expect(Partner::query()->count())->toBe(0);

    auth()->logout();
    $this->get(route('admin.partners.index'))->assertRedirect(route('login'));
});

test('the admin navigation lists Partners', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('adminNavigation.2.title', 'Partners'));
});

test('suspending an Active partner is logged and leaves its properties alone', function () {
    $partner = Partner::factory()->create();
    $property = Property::factory()->create(['partner_id' => $partner->id]);
    $user = User::factory()->partner($partner)->create();

    $this->actingAs($this->admin)
        ->post(route('admin.partners.suspend', $partner))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Partner suspended.']);

    $activity = Activity::query()->sole();

    expect($partner->refresh()->status)->toBe(PartnerStatus::Suspended)
        ->and($partner->suspended_at)->not->toBeNull()
        ->and($activity->event)->toBe('partner.suspended')
        ->and($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->subject_id)->toBe($partner->id)
        ->and($activity->properties->all())->toBe(['from' => 'active', 'to' => 'suspended'])
        ->and($property->refresh()->partner_id)->toBe($partner->id)
        ->and($user->refresh()->partner_id)->toBe($partner->id)
        ->and($user->deactivated_at)->toBeNull();
});

test('reactivating a Suspended partner clears the date and is logged', function () {
    $partner = Partner::factory()->suspended()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.partners.reactivate', $partner))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Partner reactivated.']);

    $activity = Activity::query()->sole();

    expect($partner->refresh()->status)->toBe(PartnerStatus::Active)
        ->and($partner->suspended_at)->toBeNull()
        ->and($activity->event)->toBe('partner.reactivated')
        ->and($activity->properties->all())->toBe(['from' => 'suspended', 'to' => 'active']);
});

test('only an Active partner can be suspended and only a Suspended one reactivated', function () {
    $suspended = Partner::factory()->suspended()->create();
    $invited = Partner::factory()->create(['status' => PartnerStatus::Invited]);
    $active = Partner::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.partners.suspend', $suspended))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This partner cannot be suspended.']);
    $this->post(route('admin.partners.suspend', $invited))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This partner cannot be suspended.']);
    $this->post(route('admin.partners.reactivate', $active))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'This partner is not suspended.']);

    expect($invited->refresh()->status)->toBe(PartnerStatus::Invited)
        ->and($suspended->refresh()->status)->toBe(PartnerStatus::Suspended)
        ->and($active->refresh()->status)->toBe(PartnerStatus::Active)
        ->and(Activity::query()->count())->toBe(0);
});

test('non-admins get a 404 on the suspend, reactivate and Suspended tab routes', function () {
    $partner = Partner::factory()->create();

    foreach ([User::factory()->create(), User::factory()->partner($partner)->create()] as $user) {
        $this->actingAs($user)->get(route('admin.partners.suspended'))->assertNotFound();
        $this->post(route('admin.partners.suspend', $partner))->assertNotFound();
        $this->post(route('admin.partners.reactivate', $partner))->assertNotFound();
    }

    expect($partner->refresh()->status)->toBe(PartnerStatus::Active);
});

test('the Suspended tab lists only suspended partners and the Active tab drops them', function () {
    $suspended = Partner::factory()->suspended()->create(['name' => 'Gone Stays']);
    Partner::factory()->create(['name' => 'Live Stays']);

    $this->actingAs($this->admin)
        ->get(route('admin.partners.suspended'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/partners/index')
            ->where('tab', 'suspended')
            ->has('partners.data', 1)
            ->where('partners.data.0.id', $suspended->id)
            ->where('partners.data.0.suspendedAt', $suspended->suspended_at->toDateString())
        );

    $this->get(route('admin.partners.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('partners.data', 1)
            ->where('partners.data.0.name', 'Live Stays')
        );
});

test('add with a temp password refuses an email with an open partner invitation', function () {
    PartnerInvitation::factory()->create(['email' => 'ops@andaman.test']);

    $this->actingAs($this->admin)
        ->post(route('admin.partners.store'), $this->input)
        ->assertSessionHasErrors('email');

    expect(Partner::query()->where('name', 'Andaman Stays')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'ops@andaman.test')->exists())->toBeFalse();
});
