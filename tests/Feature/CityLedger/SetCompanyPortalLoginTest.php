<?php

use App\Actions\CityLedger\SetCompanyPortalLogin;
use App\Enums\CompanyUserStatus;
use App\Enums\PortalLoginOutcome;
use App\Enums\PortalLoginRefusal;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Partner;
use App\Models\Property;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\CompanyLinkInvitation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->actor = User::factory()->owner()->create();
    $this->property = $this->actor->property;
    $this->company = Company::factory()->for($this->property)->create(['name' => 'Borneo Travel']);
    $this->set = fn (Company $company, ?string $email) => app(SetCompanyPortalLogin::class)($this->actor, $company, $email);
});

test('a new email creates the company user with a temp password and an Active link', function () {
    $result = ($this->set)($this->company, ' Booking@BorneoTravel.my ');

    $user = User::query()->where('email', 'booking@borneotravel.my')->sole();
    $link = CompanyUser::query()->sole();

    expect($result->outcome)->toBe(PortalLoginOutcome::Created)
        ->and($user->is_company_user)->toBeTrue()
        ->and($user->property_id)->toBeNull()
        ->and($user->partner_id)->toBeNull()
        ->and($user->name)->toBe('Borneo Travel')
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->temporary_password)->not->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and($link->status)->toBe(CompanyUserStatus::Active)
        ->and($link->accepted_at)->not->toBeNull()
        ->and($link->property_id)->toBe($this->property->id)
        ->and($link->company_id)->toBe($this->company->id)
        ->and($link->invited_by)->toBe($this->actor->id);

    $activity = Activity::query()->where('event', 'company.portal-login-set')->sole();
    expect($activity->properties->get('email'))->toBe('booking@borneotravel.my')
        ->and(json_encode($activity->properties))->not->toContain($user->temporary_password);
});

test('an existing company user gets a Pending link and an invitation', function () {
    Notification::fake();
    $existing = User::factory()->companyUser()->create(['email' => 'agent@travel.my']);

    $result = ($this->set)($this->company, 'agent@travel.my');

    $link = CompanyUser::query()->sole();
    expect($result->outcome)->toBe(PortalLoginOutcome::Invited)
        ->and($link->status)->toBe(CompanyUserStatus::Pending)
        ->and($link->user_id)->toBe($existing->id)
        ->and($link->last_sent_at)->not->toBeNull()
        ->and(User::query()->count())->toBe(2);

    Notification::assertSentTo($existing, CompanyLinkInvitation::class, function (CompanyLinkInvitation $notification) use ($existing, $link) {
        return $notification->via($existing) === ['mail', 'database']
            && $notification->toArray($existing)['company_user_id'] === $link->id;
    });
});

test('other kinds of account, open invitations and deactivated logins are refused and write nothing', function (string $case, PortalLoginRefusal $refusal) {
    match ($case) {
        'staff' => User::factory()->for($this->property)->create(['email' => 'x@y.my']),
        'admin' => User::factory()->admin()->create(['email' => 'x@y.my']),
        'partner' => User::factory()->partner()->create(['email' => 'x@y.my']),
        'staff invitation' => StaffInvitation::factory()->for($this->property)->create(['email' => 'x@y.my']),
        'deactivated' => User::factory()->companyUser()->deactivated()->create(['email' => 'x@y.my']),
    };
    $usersBefore = User::query()->count();

    $result = ($this->set)($this->company, 'x@y.my');

    expect($result->outcome)->toBe(PortalLoginOutcome::Refused)
        ->and($result->refusal)->toBe($refusal)
        ->and(CompanyUser::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe($usersBefore)
        ->and(Activity::query()->where('event', 'like', 'company.portal%')->count())->toBe(0);
})->with([
    ['staff', PortalLoginRefusal::OtherKindOfAccount],
    ['admin', PortalLoginRefusal::OtherKindOfAccount],
    ['partner', PortalLoginRefusal::OtherKindOfAccount],
    ['staff invitation', PortalLoginRefusal::OtherKindOfAccount],
    ['deactivated', PortalLoginRefusal::NotAvailable],
]);

test('refusal messages are the spec text', function () {
    expect(PortalLoginRefusal::OtherKindOfAccount->message())->toBe('This email belongs to another kind of estatell account.')
        ->and(PortalLoginRefusal::TakenByOtherCompany->message())->toBe('This email is already the portal login of another company here.')
        ->and(PortalLoginRefusal::NotAvailable->message())->toBe('This login is not available.');
});

test('an email that is the login of another company here is refused, but one at another property is not', function () {
    $other = Company::factory()->for($this->property)->create();
    ($this->set)($other, 'shared@travel.my');

    expect(($this->set)($this->company, 'shared@travel.my')->refusal)->toBe(PortalLoginRefusal::TakenByOtherCompany)
        ->and(CompanyUser::query()->count())->toBe(1);

    $elsewhere = Company::factory()->for(Property::factory())->create();
    $result = app(SetCompanyPortalLogin::class)($this->actor, $elsewhere, 'shared@travel.my');

    expect($result->outcome)->toBe(PortalLoginOutcome::Invited)
        ->and(CompanyUser::query()->count())->toBe(2);
});

test('an empty email revokes the open link and logs it, and does nothing without a link', function () {
    ($this->set)($this->company, 'booking@borneotravel.my');

    $result = ($this->set)($this->company, null);

    expect($result->outcome)->toBe(PortalLoginOutcome::Removed)
        ->and(CompanyUser::query()->sole()->status)->toBe(CompanyUserStatus::Revoked)
        ->and(CompanyUser::query()->sole()->revoked_at)->not->toBeNull()
        ->and(User::query()->where('email', 'booking@borneotravel.my')->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'company.portal-login-removed')->count())->toBe(1);

    expect(($this->set)($this->company, '')->outcome)->toBe(PortalLoginOutcome::Unchanged)
        ->and(Activity::query()->where('event', 'company.portal-login-removed')->count())->toBe(1);
});

test('the same email changes nothing and logs nothing', function () {
    ($this->set)($this->company, 'booking@borneotravel.my');
    $logged = Activity::query()->count();

    $result = ($this->set)($this->company, 'BOOKING@borneotravel.my');

    expect($result->outcome)->toBe(PortalLoginOutcome::Unchanged)
        ->and(CompanyUser::query()->count())->toBe(1)
        ->and(Activity::query()->count())->toBe($logged);
});

test('a changed email revokes the old link, and a refused change keeps it', function () {
    ($this->set)($this->company, 'old@travel.my');
    User::factory()->for($this->property)->create(['email' => 'staff@hotel.my']);

    expect(($this->set)($this->company, 'staff@hotel.my')->isRefused())->toBeTrue()
        ->and($this->company->portalLink()->first()->status)->toBe(CompanyUserStatus::Active);

    $result = ($this->set)($this->company, 'new@travel.my');

    expect($result->outcome)->toBe(PortalLoginOutcome::Created)
        ->and(CompanyUser::query()->where('status', CompanyUserStatus::Revoked)->count())->toBe(1)
        ->and($this->company->portalLink()->first()->user->email)->toBe('new@travel.my');
});

test('a Suspended company keeps its link and refuses a change', function () {
    ($this->set)($this->company, 'booking@borneotravel.my');
    $this->company->forceFill(['status' => 'suspended'])->save();

    expect(($this->set)($this->company, 'other@travel.my')->refusal)->toBe(PortalLoginRefusal::CompanySuspended)
        ->and(($this->set)($this->company, null)->refusal)->toBe(PortalLoginRefusal::CompanySuspended)
        ->and(($this->set)($this->company, 'booking@borneotravel.my')->outcome)->toBe(PortalLoginOutcome::Unchanged)
        ->and($this->company->portalLink()->first()->status)->toBe(CompanyUserStatus::Active);
});

test('the database keeps one kind per user and one open link per company and per user at a property', function () {
    expect(fn () => DB::transaction(fn () => User::factory()->companyUser()->create(['property_id' => $this->property->id])))->toThrow(QueryException::class);

    $link = CompanyUser::factory()->for($this->company)->create();

    expect(fn () => DB::transaction(fn () => CompanyUser::factory()->for($this->company)->pending()->create()))->toThrow(QueryException::class);

    $otherCompany = Company::factory()->for($this->property)->create();
    expect(fn () => DB::transaction(fn () => CompanyUser::factory()->for($otherCompany)->create(['user_id' => $link->user_id])))->toThrow(QueryException::class);

    $link->forceFill(['status' => CompanyUserStatus::Revoked, 'revoked_at' => now()])->save();
    CompanyUser::factory()->for($this->company)->pending()->create();

    expect(CompanyUser::query()->count())->toBe(2);
});

test('the database requires accepted_at for Active and revoked_at for Revoked', function () {
    expect(fn () => DB::transaction(fn () => CompanyUser::factory()->for($this->company)->create(['accepted_at' => null])))->toThrow(QueryException::class)
        ->and(fn () => CompanyUser::factory()->for($this->company)->revoked()->create(['revoked_at' => null]))->toThrow(QueryException::class);
});

test('a user cannot be a company user and a partner user at once', function () {
    expect(fn () => DB::transaction(fn () => User::factory()->partner(Partner::factory()->create())->create(['is_company_user' => true])))->toThrow(QueryException::class);
});
