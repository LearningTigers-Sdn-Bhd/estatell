<?php

use App\Actions\CityLedger\SetCompanyPortalLogin;
use App\Actions\Onboarding\RefreshPendingTemporaryPasswords;
use App\Enums\CompanyUserStatus;
use App\Enums\DefaultRoleEnum;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Property;
use App\Models\User;
use App\Notifications\CompanyLinkInvitation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function portalCompanyUrl(Property $property, string $suffix = ''): string
{
    return "/p/{$property->code}/finance/city-ledger/companies{$suffix}";
}

function portalCompanyWithLogin(User $actor, string $email = 'booking@borneotravel.my'): Company
{
    $company = Company::factory()->for($actor->property)->create(['name' => 'Borneo Travel']);
    app(SetCompanyPortalLogin::class)($actor, $company, $email);

    return $company;
}

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->property = $this->owner->property;
});

test('the add sheet creates the company and its login, and the message route sends the temp password', function () {
    $this->actingAs($this->owner)
        ->post(portalCompanyUrl($this->property), [
            'name' => 'Borneo Travel',
            'type' => 'travel_agent',
            'direct_bill_enabled' => true,
            'portal_email' => ' Booking@BorneoTravel.my ',
            'portal_cancel_cutoff_days' => 7,
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('portalLoginCreated', true);

    $company = Company::query()->sole();
    $user = User::query()->where('email', 'booking@borneotravel.my')->sole();

    expect($company->portal_cancel_cutoff_days)->toBe(7)
        ->and($company->portalLink->user_id)->toBe($user->id);

    $response = $this->actingAs($this->owner)
        ->postJson(portalCompanyUrl($this->property, "/{$company->id}/portal/message"))
        ->assertOk()
        ->assertJson(['count' => 1, 'skipped' => 0]);

    expect($response->json('text'))
        ->toContain('2. Email: booking@borneotravel.my')
        ->toContain('3. Temp password: '.$user->temporary_password)
        ->and($user->refresh()->temporary_password_shared_at)->not->toBeNull();
});

test('a refused portal email saves nothing and shows the error on the field', function () {
    User::factory()->for($this->property)->create(['email' => 'staff@hotel.my']);

    $this->actingAs($this->owner)
        ->post(portalCompanyUrl($this->property), [
            'name' => 'Borneo Travel',
            'type' => 'travel_agent',
            'direct_bill_enabled' => true,
            'portal_email' => 'staff@hotel.my',
        ])
        ->assertSessionHasErrors(['portal_email' => 'This email belongs to another kind of estatell account.']);

    expect(Company::query()->count())->toBe(0);
});

test('the company page sends the portal status and never a password', function () {
    $company = portalCompanyWithLogin($this->owner);

    $this->actingAs($this->owner)
        ->get(portalCompanyUrl($this->property, "/{$company->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->where('company.portal.email', 'booking@borneotravel.my')
            ->where('company.portal.status.value', 'active')
            ->where('company.portal.hasTempPassword', true)
            ->where('company.portal.canReset', true)
            ->where('company.portal.canResend', false)
            ->missing('company.portal.temporaryPassword')
        );
});

test('the Portal form sets, changes and clears the login and the cancel days', function () {
    $company = Company::factory()->for($this->property)->create();
    $put = fn (array $data) => $this->actingAs($this->owner)->put(portalCompanyUrl($this->property, "/{$company->id}/portal"), $data);

    $put(['portal_email' => 'a@travel.my', 'portal_cancel_cutoff_days' => 5])
        ->assertRedirect(portalCompanyUrl($this->property, "/{$company->id}"))
        ->assertInertiaFlash('portalLoginCreated', true);
    expect($company->refresh()->portal_cancel_cutoff_days)->toBe(5)
        ->and($company->portalLink->user->email)->toBe('a@travel.my');

    $put(['portal_email' => 'a@travel.my', 'portal_cancel_cutoff_days' => 5])->assertInertiaFlash('portalLoginCreated', false);

    $put(['portal_email' => null, 'portal_cancel_cutoff_days' => null])->assertSessionHasNoErrors();
    expect($company->refresh()->portal_cancel_cutoff_days)->toBeNull()
        ->and($company->portalLink()->exists())->toBeFalse()
        ->and($company->companyUsers()->sole()->status)->toBe(CompanyUserStatus::Revoked);
});

test('the Details and Credit forms leave the portal login alone', function () {
    $company = portalCompanyWithLogin($this->owner);

    $this->actingAs($this->owner)
        ->put(portalCompanyUrl($this->property, "/{$company->id}/credit"), ['credit_limit' => '100', 'payment_terms_days' => 30, 'direct_bill_enabled' => true])
        ->assertSessionHasNoErrors();

    expect($company->portalLink()->exists())->toBeTrue();
});

test('a Suspended company refuses a portal change', function () {
    $company = portalCompanyWithLogin($this->owner);
    $company->forceFill(['status' => 'suspended'])->save();

    $this->actingAs($this->owner)
        ->put(portalCompanyUrl($this->property, "/{$company->id}/portal"), ['portal_email' => 'other@travel.my', 'portal_cancel_cutoff_days' => null])
        ->assertSessionHasErrors('portal_email');

    expect($company->portalLink->user->email)->toBe('booking@borneotravel.my');
});

test('an existing company user is invited and the property can resend', function () {
    Notification::fake();
    $existing = User::factory()->companyUser()->create(['email' => 'agent@travel.my']);
    $company = Company::factory()->for($this->property)->create();

    $this->actingAs($this->owner)
        ->put(portalCompanyUrl($this->property, "/{$company->id}/portal"), ['portal_email' => 'agent@travel.my', 'portal_cancel_cutoff_days' => null])
        ->assertInertiaFlash('portalLoginCreated', false);

    Notification::assertSentToTimes($existing, CompanyLinkInvitation::class, 1);

    $this->actingAs($this->owner)
        ->post(portalCompanyUrl($this->property, "/{$company->id}/portal/resend"))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Invitation sent again.']);

    Notification::assertSentToTimes($existing, CompanyLinkInvitation::class, 2);

    $company->portalLink->forceFill(['status' => CompanyUserStatus::Active, 'accepted_at' => now()])->save();
    $this->actingAs($this->owner)
        ->post(portalCompanyUrl($this->property, "/{$company->id}/portal/resend"))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'There is no invitation to send again.']);
});

test('reset gives a new temp password only while the user has not set their own and is not linked elsewhere', function () {
    $company = portalCompanyWithLogin($this->owner);
    $user = $company->portalLink->user;
    $old = $user->temporary_password;
    $reset = fn () => $this->actingAs($this->owner)->post(portalCompanyUrl($this->property, "/{$company->id}/portal/reset"));

    $reset()->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'New temp password made. Use Copy message to share it.']);
    $user->refresh();
    expect($user->temporary_password)->not->toBe($old)
        ->and(Hash::check($user->temporary_password, $user->password))->toBeTrue()
        ->and(Activity::query()->where('event', 'company.portal-temp-password-reset')->count())->toBe(1);

    $elsewhere = Company::factory()->for(Property::factory())->create();
    CompanyUser::factory()->for($elsewhere)->create(['user_id' => $user->id]);
    $reset()->assertInertiaFlash('toast.type', 'error');
    expect(Activity::query()->where('event', 'company.portal-temp-password-reset')->count())->toBe(1);

    CompanyUser::query()->where('company_id', $elsewhere->id)->delete();
    $user->forceFill(['must_change_password' => false, 'temporary_password' => null, 'temporary_password_expires_at' => null])->save();
    $reset()->assertInertiaFlash('toast.type', 'error');
});

test('the portal routes need company.manage and stay inside the property', function () {
    $company = portalCompanyWithLogin($this->owner);
    $viewer = User::factory()->for($this->property)->withRole(DefaultRoleEnum::FrontDesk)->create();
    $outsider = User::factory()->owner()->create();

    foreach (['message', 'reset', 'resend'] as $action) {
        $this->actingAs($viewer)->post(portalCompanyUrl($this->property, "/{$company->id}/portal/{$action}"))->assertForbidden();
        $this->actingAs($outsider)->post(portalCompanyUrl($outsider->property, "/{$company->id}/portal/{$action}"))->assertNotFound();
    }

    $this->actingAs($viewer)->put(portalCompanyUrl($this->property, "/{$company->id}/portal"), ['portal_email' => 'x@y.my'])->assertForbidden();
});

test('bulk add creates logins, lists only new temp password logins and exports them', function () {
    User::factory()->companyUser()->create(['email' => 'old@travel.my']);

    $response = $this->actingAs($this->owner)
        ->post(portalCompanyUrl($this->property, '/bulk'), ['rows' => [
            ['name' => 'Alpha', 'type' => 'corporate', 'direct_bill_enabled' => true, 'portal_email' => 'alpha@travel.my', 'portal_cancel_cutoff_days' => '3'],
            ['name' => 'Beta', 'type' => 'corporate', 'direct_bill_enabled' => true, 'portal_email' => 'old@travel.my'],
            ['name' => 'Gamma', 'type' => 'corporate', 'direct_bill_enabled' => true],
        ]])
        ->assertSessionHasNoErrors()
        ->assertRedirect(portalCompanyUrl($this->property, '/bulk'));

    $alpha = User::query()->where('email', 'alpha@travel.my')->sole();
    $response->assertInertiaFlash('bulkAdded', [['id' => $alpha->id, 'name' => 'Alpha', 'email' => 'alpha@travel.my']]);

    expect(Company::query()->where('name', 'Alpha')->sole()->portal_cancel_cutoff_days)->toBe(3)
        ->and(CompanyUser::query()->where('status', CompanyUserStatus::Pending)->count())->toBe(1);

    $this->actingAs($this->owner)
        ->postJson(portalCompanyUrl($this->property, '/portal-logins/message'), ['user_ids' => [$alpha->id]])
        ->assertOk()
        ->assertJson(['count' => 1]);

    $this->actingAs($this->owner)
        ->post(portalCompanyUrl($this->property, '/portal-logins/export'), ['user_ids' => [$alpha->id], 'format' => 'csv'])
        ->assertOk()
        ->assertDownload();

    $this->actingAs($this->owner)
        ->postJson(portalCompanyUrl($this->property, '/portal-logins/message'), ['user_ids' => [$this->owner->id]])
        ->assertUnprocessable();
});

test('bulk add rejects a portal email twice in the list and one that is refused', function () {
    User::factory()->for($this->property)->create(['email' => 'staff@hotel.my']);

    $this->actingAs($this->owner)
        ->post(portalCompanyUrl($this->property, '/bulk'), ['rows' => [
            ['name' => 'Alpha', 'type' => 'corporate', 'direct_bill_enabled' => true, 'portal_email' => 'same@travel.my'],
            ['name' => 'Beta', 'type' => 'corporate', 'direct_bill_enabled' => true, 'portal_email' => 'same@travel.my'],
            ['name' => 'Gamma', 'type' => 'corporate', 'direct_bill_enabled' => true, 'portal_email' => 'staff@hotel.my'],
        ]])
        ->assertSessionHasErrors(['rows.0.portal_email', 'rows.1.portal_email', 'rows.2.portal_email']);

    expect(Company::query()->count())->toBe(0);
});

test('the bulk import file reads the portal columns', function () {
    $path = sys_get_temp_dir().'/'.uniqid('portal-import-').'.csv';
    file_put_contents($path, "name,type,portal_email,cancel_days\nAlpha,corporate,Alpha@Travel.my,5\n");
    $file = new UploadedFile($path, 'companies.csv', null, null, true);

    $this->actingAs($this->owner)
        ->postJson(portalCompanyUrl($this->property, '/import'), ['file' => $file])
        ->assertOk()
        ->assertJsonPath('rows.0.portal_email', 'alpha@travel.my')
        ->assertJsonPath('rows.0.portal_cancel_cutoff_days', '5');
});

test('the go-live refresh gives a new temp password to company users who have not signed in, not those linked elsewhere', function () {
    $company = portalCompanyWithLogin($this->owner);
    $user = $company->portalLink->user;
    $other = Company::factory()->for($this->property)->create();
    app(SetCompanyPortalLogin::class)($this->owner, $other, 'two@travel.my');
    $twoUser = $other->portalLink->user;
    CompanyUser::factory()->for(Company::factory()->for(Property::factory())->create())->create(['user_id' => $twoUser->id]);
    $before = $user->temporary_password;
    $twoBefore = $twoUser->temporary_password;
    $this->travelTo(now()->addDays(10));

    $counts = app(RefreshPendingTemporaryPasswords::class)($this->property, $this->owner);

    expect($counts['companies'])->toBe(1)
        ->and($user->refresh()->temporary_password)->not->toBe($before)
        ->and($user->temporary_password_expires_at->isSameDay(now()->addDays(14)))->toBeTrue()
        ->and($twoUser->refresh()->temporary_password)->toBe($twoBefore)
        ->and(Activity::query()->where('event', 'company.temp-password-refreshed')->count())->toBe(1);
});
