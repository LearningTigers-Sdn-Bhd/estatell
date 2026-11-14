<?php

use App\Actions\Account\PruneSignInEvents;
use App\Enums\SignInResult;
use App\Models\Property;
use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

const SIGN_IN_LOG_CHROME_ON_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

it('records a sign-in with the country and device, and keeps the IP address encrypted', function () {
    $user = User::factory()->for(Property::factory())->create();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeaders(['User-Agent' => SIGN_IN_LOG_CHROME_ON_WINDOWS, 'CF-IPCountry' => 'MY'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $event = SignInEvent::query()->sole();

    expect($event->user_id)->toBe($user->id)
        ->and($event->result)->toBe(SignInResult::Succeeded)
        ->and($event->ip_address)->toBe('203.0.113.7')
        ->and($event->country_code)->toBe('MY')
        ->and($event->os)->toBe('Windows')
        ->and($event->browser)->toBe('Chrome')
        ->and(DB::table('sign_in_events')->value('ip_address'))->not->toContain('203.0.113.7');
});

it('links a wrong password to the account and stores no email for an unknown one', function () {
    $user = User::factory()->for(Property::factory())->create(['email' => 'staff@example.test']);

    $this->post(route('login.store'), ['email' => 'Staff@Example.test', 'password' => 'wrong-password']);
    $this->post(route('login.store'), ['email' => 'nobody@example.test', 'password' => 'wrong-password']);

    $rows = SignInEvent::query()->orderBy('id')->get();

    expect($rows->pluck('result')->all())->toBe([SignInResult::Failed, SignInResult::Failed])
        ->and($rows->pluck('user_id')->all())->toBe([$user->id, null])
        ->and(json_encode(DB::table('sign_in_events')->get()))->not->toContain('nobody');
});

it('records a lockout once the login throttle is hit', function () {
    $user = User::factory()->for(Property::factory())->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])->assertTooManyRequests();

    expect(SignInEvent::query()->where('result', SignInResult::LockedOut)->sole()->user_id)->toBe($user->id)
        ->and(SignInEvent::query()->where('result', SignInResult::Failed)->count())->toBe(5);
});

it('records a wrong two-factor code', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
    $user = User::factory()->for(Property::factory())->withTwoFactor()->create();

    $this->withSession(['login.id' => $user->id, 'login.remember' => false])
        ->post(route('two-factor.login.store'), ['recovery_code' => 'not-a-code']);

    expect(SignInEvent::query()->sole())
        ->result->toBe(SignInResult::TwoFactorFailed)
        ->user_id->toBe($user->id);
});

it('ignores unknown and Tor countries from Cloudflare', function (string $header) {
    $user = User::factory()->for(Property::factory())->create();

    $this->withHeaders(['CF-IPCountry' => $header])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect(SignInEvent::query()->sole()->country_code)->toBeNull();
})->with(['XX', 'T1']);

it('does not record support access switching between users', function () {
    $property = Property::factory()->create();
    $owner = User::factory()->owner()->create(['property_id' => $property->id]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('view-as.store', $property->code), ['user' => $owner->id, 'reason' => 'support']);
    $this->post(route('impersonation.stop'));

    expect(auth()->id())->toBe($admin->id)
        ->and(SignInEvent::query()->count())->toBe(0);
});

it('prunes only rows older than 90 days with their blind index', function () {
    $old = SignInEvent::factory()->create(['occurred_at' => now()->subDays(91)]);
    $recent = SignInEvent::factory()->create(['occurred_at' => now()->subDays(89)]);

    expect(app(PruneSignInEvents::class)())->toBe(1)
        ->and(SignInEvent::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(SignInEvent::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(DB::table('blind_indexes')->where('indexable_type', SignInEvent::class)->where('indexable_id', $old->id)->exists())->toBeFalse()
        ->and(app(PruneSignInEvents::class)())->toBe(0);
});

it('lists the sign-in log for admins with search, result, property and date filters', function () {
    $admin = User::factory()->admin()->create();
    $property = Property::factory()->create();
    $amir = User::factory()->for($property)->create(['name' => 'Amir Hamzah', 'email' => 'amir@example.test']);
    $other = User::factory()->for(Property::factory())->create(['name' => 'Siti']);
    SignInEvent::factory()->for($amir)->create(['ip_address' => '198.51.100.4']);
    SignInEvent::factory()->for($other)->failed()->create(['ip_address' => '198.51.100.9', 'occurred_at' => now()->subHour()]);
    SignInEvent::factory()->failed()->create(['occurred_at' => now()->subDays(30)]);

    $ids = fn (array $query) => collect($this->actingAs($admin)->get(route('admin.sign-in-events.index', $query))
        ->assertOk()
        ->viewData('page')['props']['events']['data'])->pluck('userName')->all();

    expect($ids([]))->toHaveCount(3)
        ->and($ids(['filter' => ['search' => '198.51.100.4']]))->toBe(['Amir Hamzah'])
        ->and($ids(['filter' => ['search' => 'AMIR@example.test']]))->toBe(['Amir Hamzah'])
        ->and($ids(['filter' => ['search' => 'Sit']]))->toBe(['Siti'])
        ->and($ids(['filter' => ['result' => 'failed']]))->toHaveCount(2)
        ->and($ids(['filter' => ['property' => (string) $property->id]]))->toBe(['Amir Hamzah'])
        ->and($ids(['filter' => ['from' => now()->subDay()->toDateString()]]))->toHaveCount(2);

    $this->actingAs($admin)->get(route('admin.sign-in-events.index'))->assertInertia(fn (Assert $page) => $page
        ->component('admin/sign-in-events/index')
        ->where('counts', ['succeeded' => 1, 'failed' => 2, 'two_factor_failed' => 0, 'locked_out' => 0])
        ->where('events.data.0.userWhere', $property->name));
});

it('hides the sign-in log from property staff', function () {
    $staff = User::factory()->owner()->create(['property_id' => Property::factory()->create()->id]);

    $this->actingAs($staff)->get(route('admin.sign-in-events.index'))->assertNotFound();
});
