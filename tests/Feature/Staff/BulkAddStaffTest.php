<?php

use App\Enums\DefaultRoleEnum;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

function bulkAddRole(Property $property, DefaultRoleEnum $defaultRole): Role
{
    return Role::query()->where('property_id', $property->id)->where('default_role', $defaultRole)->firstOrFail();
}

/**
 * @param  list<list<string>>  $rows  the first row is the header
 */
function staffImportFile(array $rows, string $extension = 'csv'): UploadedFile
{
    $path = sys_get_temp_dir().'/'.uniqid('staff-import-').'.'.$extension;
    $writer = SimpleExcelWriter::create($path)->noHeaderRow();
    foreach ($rows as $row) {
        $writer->addRow($row);
    }
    $writer->close();

    return new UploadedFile($path, 'staff.'.$extension, null, null, true);
}

test('owners open the bulk add page with their roles', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get(route('settings.staff.bulk.create', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/staff/bulk')
            ->where('maxRows', 200)
            ->has('roles', 5));
});

test('owners bulk add staff with temp passwords in one go', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $frontDesk = bulkAddRole($property, DefaultRoleEnum::FrontDesk)->id;

    $this->actingAs($owner)
        ->post(route('settings.staff.bulk.store', $property->code), ['rows' => [
            ['name' => 'Ali Hassan', 'email' => 'Ali@Hotel.com', 'role_id' => $frontDesk],
            ['name' => 'Siti Aminah', 'email' => 'siti@hotel.com', 'role_id' => bulkAddRole($property, DefaultRoleEnum::Housekeeping)->id],
        ]])
        ->assertRedirect(route('settings.staff.bulk.create', $property->code))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '2 staff added.'])
        ->assertInertiaFlash('bulkAdded.0.email', 'ali@hotel.com')
        ->assertInertiaFlash('bulkAdded.1.name', 'Siti Aminah');

    $created = User::query()->whereIn('email', ['ali@hotel.com', 'siti@hotel.com'])->get();

    expect($created)->toHaveCount(2)
        ->and($created->every(fn (User $user): bool => $user->must_change_password && $user->temporary_password !== null && $user->email_verified_at !== null))->toBeTrue()
        ->and(Activity::query()->where('event', 'staff.created-with-temp-password')->count())->toBe(2);
});

test('one bad row saves nothing and the errors point at their rows', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $frontDesk = bulkAddRole($property, DefaultRoleEnum::FrontDesk)->id;
    User::factory()->create(['email' => 'taken@hotel.com']);

    $this->actingAs($owner)
        ->post(route('settings.staff.bulk.store', $property->code), ['rows' => [
            ['name' => 'Ali', 'email' => 'ali@hotel.com', 'role_id' => $frontDesk],
            ['name' => 'Ali again', 'email' => 'ALI@hotel.com', 'role_id' => $frontDesk],
            ['name' => 'Taken', 'email' => 'taken@hotel.com', 'role_id' => $frontDesk],
            ['name' => '', 'email' => 'not-an-email', 'role_id' => ''],
        ]])
        ->assertSessionHasErrors([
            'rows.1.email' => 'This email is in the list twice.',
            'rows.2.email' => 'This email already has an account or an open invitation.',
            'rows.3.name' => 'Enter a name.',
            'rows.3.email' => 'Enter a valid email.',
            'rows.3.role_id' => 'Choose a role.',
        ]);

    expect(User::query()->where('email', 'ali@hotel.com')->exists())->toBeFalse()
        ->and(Activity::query()->count())->toBe(0);
});

test('bulk add takes at most 200 rows and only roles the actor may give', function () {
    $manager = User::factory()->withRole(DefaultRoleEnum::Manager)->create();
    $property = $manager->property;
    $owner = bulkAddRole($property, DefaultRoleEnum::Owner)->id;
    $rows = array_map(fn (int $i): array => ['name' => "Staff {$i}", 'email' => "staff{$i}@hotel.com", 'role_id' => $owner], range(1, 201));

    $this->actingAs($manager)
        ->post(route('settings.staff.bulk.store', $property->code), ['rows' => $rows])
        ->assertSessionHasErrors('rows');

    $this->post(route('settings.staff.bulk.store', $property->code), ['rows' => [$rows[0]]])
        ->assertSessionHasErrors(['rows.0.role_id' => 'Choose a role you are allowed to give.']);
});

test('bulk add needs staff.manage and the own property', function () {
    $frontDesk = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->create();
    $owner = User::factory()->owner()->create();
    $other = Property::factory()->create();

    $this->actingAs($frontDesk)->get(route('settings.staff.bulk.create', $frontDesk->property->code))->assertForbidden();
    $this->actingAs($frontDesk)->post(route('settings.staff.import', $frontDesk->property->code), ['file' => staffImportFile([['name', 'email', 'role']])])->assertForbidden();
    $this->actingAs($owner)->get(route('settings.staff.bulk.create', $other->code))->assertNotFound();
});

test('an uploaded CSV fills rows and matches roles by name', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;

    $this->actingAs($owner)
        ->post(route('settings.staff.import', $property->code), ['file' => staffImportFile([
            ['Name', ' Email ', 'ROLE'],
            ['Ali Hassan', 'Ali@Hotel.com', 'front desk'],
            ['', '', ''],
            ['Siti', 'siti@hotel.com', 'Chef'],
            ['Mei', 'mei@hotel.com', ''],
        ])])
        ->assertOk()
        ->assertExactJson(['rows' => [
            ['name' => 'Ali Hassan', 'email' => 'ali@hotel.com', 'roleId' => bulkAddRole($property, DefaultRoleEnum::FrontDesk)->id, 'problem' => null],
            ['name' => 'Siti', 'email' => 'siti@hotel.com', 'roleId' => null, 'problem' => 'Unknown role "Chef".'],
            ['name' => 'Mei', 'email' => 'mei@hotel.com', 'roleId' => null, 'problem' => 'Choose a role.'],
        ]]);

    expect(User::query()->count())->toBe(1);
});

test('an uploaded XLSX file is read the same way', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->post(route('settings.staff.import', $owner->property->code), ['file' => staffImportFile([
            ['name', 'email', 'role'],
            ['Ali', 'ali@hotel.com', 'Housekeeping'],
        ], 'xlsx')])
        ->assertOk()
        ->assertJsonPath('rows.0.roleId', bulkAddRole($owner->property, DefaultRoleEnum::Housekeeping)->id);
});

test('imports refuse a wrong header, too many rows and other file types', function () {
    $owner = User::factory()->owner()->create();
    $url = route('settings.staff.import', $owner->property->code);

    $this->actingAs($owner)
        ->postJson($url, ['file' => staffImportFile([['full name', 'email'], ['Ali', 'ali@hotel.com']])])
        ->assertJsonValidationErrors(['file' => 'The first row must have the columns name, email and role.']);

    $tooMany = [['name', 'email', 'role'], ...array_map(fn (int $i): array => ["Staff {$i}", "s{$i}@hotel.com", 'Front desk'], range(1, 201))];
    $this->postJson($url, ['file' => staffImportFile($tooMany)])
        ->assertJsonValidationErrors(['file' => 'The file has more than 200 rows.']);

    $this->postJson($url, ['file' => UploadedFile::fake()->create('staff.pdf', 10, 'application/pdf')])
        ->assertJsonValidationErrors('file');
});

test('the import template has the name, email and role columns', function (string $format) {
    $owner = User::factory()->owner()->create();

    $response = $this->actingAs($owner)
        ->get(route('settings.staff.import-template', [$owner->property->code, $format]))
        ->assertOk()
        ->assertDownload("staff-import.{$format}");

    $path = $response->baseResponse->getFile()->getPathname();
    $copy = sys_get_temp_dir().'/'.uniqid('template-').'.'.$format;
    copy($path, $copy);

    expect(SimpleExcelReader::create($copy)->getHeaders())->toBe(['name', 'email', 'role']);
})->with(['csv', 'xlsx']);

test('the import template only comes as csv or xlsx', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)
        ->get(route('settings.staff.import-template', [$owner->property->code, 'pdf']))
        ->assertNotFound();
});
