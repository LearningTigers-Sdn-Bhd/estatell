<?php

use App\Actions\Platform\CreatePartner;
use App\Actions\Property\IssuePropertyCode;
use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

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
        ->assertInertiaFlash('partnerSignInMessage');

    $partner = Partner::query()->sole();
    $user = User::query()->where('partner_id', $partner->id)->sole();

    expect($partner->status)->toBe(PartnerStatus::Active)
        ->and($partner->code_prefix)->toBe('AS')
        ->and($partner->signup_code)->toMatch('/^[A-HJ-NP-Z2-9]{6}$/')
        ->and(DB::table('property_code_counters')->where('prefix', 'AS')->value('last_number'))->toBe(10100)
        ->and($user->email)->toBe('ops@andaman.test')
        ->and($user->property_id)->toBeNull()
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->temporary_password)->not->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull();

    DB::transaction(fn () => expect(app(IssuePropertyCode::class)('AS'))->toBe('AS10101'));

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
