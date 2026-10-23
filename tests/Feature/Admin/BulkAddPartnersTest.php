<?php

use App\Actions\Platform\BulkCreatePartners;
use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\PartnerInvitation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

/**
 * @param  list<list<string>>  $rows  the first row is the header
 */
function partnerImportFile(array $rows, string $extension = 'csv'): UploadedFile
{
    $path = sys_get_temp_dir().'/'.uniqid('partner-import-').'.'.$extension;
    $writer = SimpleExcelWriter::create($path)->noHeaderRow();
    foreach ($rows as $row) {
        $writer->addRow($row);
    }
    $writer->close();

    return new UploadedFile($path, 'partners.'.$extension, null, null, true);
}

function bulkPartnerRow(string $partnerName, string $prefix, string $name, string $email): array
{
    return ['partner_name' => $partnerName, 'code_prefix' => $prefix, 'name' => $name, 'email' => $email];
}

test('admins open the bulk add page with the row limit and download formats', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.partners.bulk.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/partners/bulk')
            ->where('maxRows', 200)
            ->has('exportFormats', 3));
});

test('a user who is not an admin gets 404 on the bulk add page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.partners.bulk.create'))
        ->assertNotFound();
});

test('bulk add creates every partner with its prefix, counter, login and a log each', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.partners.bulk.store'), ['rows' => [
            bulkPartnerRow('Andaman Stays', 'AS', 'Aina Rahman', 'ops@andaman.test'),
            bulkPartnerRow('Kota Partners', 'KP', 'Kamal Ong', 'kp@kota.test'),
        ]])
        ->assertRedirect(route('admin.partners.bulk.create'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '2 partners added.'])
        ->assertInertiaFlash('bulkAdded.0.partner', 'Andaman Stays')
        ->assertInertiaFlash('bulkAdded.1.email', 'kp@kota.test');

    $partners = Partner::query()->orderBy('code_prefix')->get();

    expect($partners->pluck('code_prefix')->all())->toBe(['AS', 'KP'])
        ->and($partners->every(fn (Partner $partner): bool => $partner->status === PartnerStatus::Active && $partner->signup_code !== null))->toBeTrue()
        ->and(DB::table('property_code_counters')->whereIn('prefix', ['AS', 'KP'])->pluck('last_number')->all())->toBe([1100, 1100]);

    $users = User::query()->whereIn('email', ['ops@andaman.test', 'kp@kota.test'])->get();

    expect($users)->toHaveCount(2)
        ->and($users->every(fn (User $user): bool => $user->partner_id !== null && $user->must_change_password && $user->temporary_password !== null && $user->email_verified_at !== null))->toBeTrue()
        ->and(Activity::query()->where('event', 'partner.created-with-temp-password')->count())->toBe(2);
});

test('bulk add refuses the ES prefix, a taken prefix and a repeated prefix and saves nothing', function () {
    Partner::factory()->create(['code_prefix' => 'KP']);

    $this->actingAs($this->admin)
        ->post(route('admin.partners.bulk.store'), ['rows' => [
            bulkPartnerRow('Direct', 'ES', 'Ali', 'ali@direct.test'),
            bulkPartnerRow('Kota Two', 'KP', 'Siti', 'siti@kota.test'),
            bulkPartnerRow('Andaman Stays', 'AS', 'Aina', 'aina@andaman.test'),
            bulkPartnerRow('Andaman Again', 'as', 'Ahmad', 'ahmad@andaman.test'),
        ]])
        ->assertSessionHasErrors([
            'rows.0.code_prefix' => 'ES is reserved for direct properties.',
            'rows.1.code_prefix' => 'This prefix is already taken.',
            'rows.3.code_prefix' => 'This prefix is in the list twice.',
        ]);

    expect(Partner::query()->count())->toBe(1)
        ->and(User::query()->where('email', 'aina@andaman.test')->exists())->toBeFalse()
        ->and(Activity::query()->count())->toBe(0);
});

test('bulk add refuses an email that has an account or repeats in the list and saves nothing', function () {
    User::factory()->create(['email' => 'taken@partner.test']);

    $this->actingAs($this->admin)
        ->post(route('admin.partners.bulk.store'), ['rows' => [
            bulkPartnerRow('Andaman Stays', 'AS', 'Aina', 'Taken@Partner.test'),
            bulkPartnerRow('Kota Partners', 'KP', 'Kamal', 'kamal@kota.test'),
            bulkPartnerRow('Kota Two', 'KT', 'Kamal again', 'KAMAL@kota.test'),
        ]])
        ->assertSessionHasErrors([
            'rows.0.email' => 'The email has already been taken.',
            'rows.2.email' => 'This email is in the list twice.',
        ]);

    expect(Partner::query()->count())->toBe(0)
        ->and(User::query()->where('email', 'kamal@kota.test')->exists())->toBeFalse();
});

test('one bad row saves nothing and the errors point at their rows', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.partners.bulk.store'), ['rows' => [
            bulkPartnerRow('Andaman Stays', 'AS', 'Aina', 'ops@andaman.test'),
            bulkPartnerRow('', 'KP', '', 'not-an-email'),
            bulkPartnerRow('Kota Partners', '', 'Kamal', 'kp@kota.test'),
        ]])
        ->assertSessionHasErrors([
            'rows.1.partner_name' => 'Enter a partner name.',
            'rows.1.name' => 'Enter a name.',
            'rows.1.email' => 'Enter a valid email.',
            'rows.2.code_prefix' => 'Enter a prefix.',
        ]);

    expect(Partner::query()->count())->toBe(0)
        ->and(User::query()->where('email', 'ops@andaman.test')->exists())->toBeFalse()
        ->and(Activity::query()->count())->toBe(0);
});

test('bulk add takes at most 200 rows', function () {
    $rows = array_map(
        fn (int $i): array => bulkPartnerRow("Partner {$i}", 'AS', "User {$i}", "user{$i}@partner.test"),
        range(1, 201),
    );

    $this->actingAs($this->admin)
        ->post(route('admin.partners.bulk.store'), ['rows' => $rows])
        ->assertSessionHasErrors('rows');

    expect(Partner::query()->count())->toBe(0);
});

test('bulk add stores prefixes in capitals and emails in lower case', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.partners.bulk.store'), ['rows' => [
            bulkPartnerRow('  Andaman Stays ', ' as ', ' Aina ', ' Ops@Andaman.TEST '),
        ]])
        ->assertSessionHasNoErrors();

    expect(Partner::query()->sole()->code_prefix)->toBe('AS')
        ->and(User::query()->where('email', 'ops@andaman.test')->exists())->toBeTrue();
});

test('the flash of a bulk add never carries a temp password', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.partners.bulk.store'), ['rows' => [
            bulkPartnerRow('Andaman Stays', 'AS', 'Aina', 'ops@andaman.test'),
        ]])
        ->assertInertiaFlash('bulkAdded.0.email', 'ops@andaman.test');

    $temporaryPassword = User::query()->where('email', 'ops@andaman.test')->sole()->temporary_password;

    expect($temporaryPassword)->not->toBeNull()
        ->and(json_encode($this->app['session.store']->all()))->not->toContain($temporaryPassword);
});

test('the copy message and the export work for the new partners', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.partners.bulk.store'), ['rows' => [
            bulkPartnerRow('Andaman Stays', 'AS', 'Aina', 'ops@andaman.test'),
            bulkPartnerRow('Kota Partners', 'KP', 'Kamal', 'kp@kota.test'),
        ]]);

    $userIds = User::query()->whereIn('email', ['ops@andaman.test', 'kp@kota.test'])->pluck('id')->all();

    $this->actingAs($this->admin)
        ->postJson(route('admin.partners.temporary-passwords.message'), ['user_ids' => $userIds])
        ->assertOk()
        ->assertJsonPath('count', 2)
        ->assertJsonPath('skipped', 0);

    $this->actingAs($this->admin)
        ->post(route('admin.partners.temporary-passwords.export'), ['user_ids' => $userIds, 'format' => 'csv'])
        ->assertOk()
        ->assertDownload('temp-passwords-partners.csv');
});

test('bulk add needs support.2fa when the switch is on', function () {
    config(['estatell.support_require_two_factor' => true]);

    $this->actingAs($this->admin)
        ->from(route('admin.partners.bulk.create'))
        ->post(route('admin.partners.bulk.store'), ['rows' => [
            bulkPartnerRow('Andaman Stays', 'AS', 'Aina', 'ops@andaman.test'),
        ]])
        ->assertRedirect(route('admin.partners.bulk.create'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);

    expect(Partner::query()->count())->toBe(0);
});

test('a prefix or email taken during save saves nothing and shows the notice', function () {
    $this->mock(BulkCreatePartners::class, function ($mock): void {
        $mock->shouldReceive('__invoke')
            ->once()
            ->andThrow(new UniqueConstraintViolationException('pgsql', 'insert', [], new Exception('duplicate key')));
    });

    $this->actingAs($this->admin)
        ->from(route('admin.partners.bulk.create'))
        ->post(route('admin.partners.bulk.store'), ['rows' => [
            bulkPartnerRow('Andaman Stays', 'AS', 'Aina', 'ops@andaman.test'),
        ]])
        ->assertRedirect(route('admin.partners.bulk.create'))
        ->assertSessionHasErrors(['rows' => 'A prefix or email was taken while you saved. Nothing was saved.']);
});

test('an uploaded CSV fills rows and a blank prefix stays blank', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.partners.bulk.import'), ['file' => partnerImportFile([
            ['Partner name', ' Prefix ', 'User Name', 'Email'],
            ['Andaman Stays', 'as', 'Aina Rahman', 'Ops@Andaman.test'],
            ['', '', '', ''],
            ['Kota Partners', '', 'Kamal Ong', 'kp@kota.test'],
        ])])
        ->assertOk()
        ->assertExactJson(['rows' => [
            ['partner_name' => 'Andaman Stays', 'code_prefix' => 'AS', 'name' => 'Aina Rahman', 'email' => 'ops@andaman.test'],
            ['partner_name' => 'Kota Partners', 'code_prefix' => '', 'name' => 'Kamal Ong', 'email' => 'kp@kota.test'],
        ]]);

    expect(Partner::query()->count())->toBe(0);
});

test('an uploaded XLSX file is read the same way', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.partners.bulk.import'), ['file' => partnerImportFile([
            ['partner_name', 'prefix', 'user_name', 'email'],
            ['Andaman Stays', 'AS', 'Aina Rahman', 'ops@andaman.test'],
        ], 'xlsx')])
        ->assertOk()
        ->assertExactJson(['rows' => [
            ['partner_name' => 'Andaman Stays', 'code_prefix' => 'AS', 'name' => 'Aina Rahman', 'email' => 'ops@andaman.test'],
        ]]);
});

test('imports refuse a wrong heading, too many rows and other file types', function () {
    $url = route('admin.partners.bulk.import');

    $this->actingAs($this->admin)
        ->postJson($url, ['file' => partnerImportFile([['partner', 'email'], ['Andaman', 'ops@andaman.test']])])
        ->assertJsonValidationErrors(['file' => 'The first row must have the columns partner name, prefix, user name and email.']);

    $tooMany = [['partner_name', 'prefix', 'user_name', 'email'], ...array_map(
        fn (int $i): array => ["Partner {$i}", 'AS', "User {$i}", "user{$i}@partner.test"],
        range(1, 201),
    )];
    $this->actingAs($this->admin)
        ->postJson($url, ['file' => partnerImportFile($tooMany)])
        ->assertJsonValidationErrors(['file' => 'The file has more than 200 rows.']);

    $this->actingAs($this->admin)
        ->postJson($url, ['file' => UploadedFile::fake()->create('partners.pdf', 10, 'application/pdf')])
        ->assertJsonValidationErrors('file');
});

test('the import template has the four columns as csv and xlsx', function (string $format) {
    $response = $this->actingAs($this->admin)
        ->get(route('admin.partners.bulk.import-template', $format))
        ->assertOk()
        ->assertDownload("partners-import.{$format}");

    $copy = sys_get_temp_dir().'/'.uniqid('template-').'.'.$format;
    copy($response->baseResponse->getFile()->getPathname(), $copy);

    expect(SimpleExcelReader::create($copy)->getHeaders())->toBe(['partner_name', 'prefix', 'user_name', 'email']);
})->with(['csv', 'xlsx']);

test('the import template only comes as csv or xlsx', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.partners.bulk.import-template', 'pdf'))
        ->assertNotFound();
});

test('bulk add refuses an email with an open partner invitation and saves nothing', function () {
    PartnerInvitation::factory()->create(['email' => 'invited@partner.test']);

    $this->actingAs($this->admin)
        ->post(route('admin.partners.bulk.store'), ['rows' => [
            bulkPartnerRow('Andaman Stays', 'AS', 'Aina', 'Invited@Partner.test'),
        ]])
        ->assertSessionHasErrors(['rows.0.email' => 'This email already has an open partner invitation.']);

    expect(Partner::query()->where('code_prefix', 'AS')->exists())->toBeFalse();
});
