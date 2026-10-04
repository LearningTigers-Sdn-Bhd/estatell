<?php

use App\Enums\DefaultRoleEnum;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * Copy a downloaded file before the response deletes it, and return the copy's path.
 */
function exportedFile(TestResponse $response, string $extension): string
{
    $copy = sys_get_temp_dir().'/'.uniqid('export-').'.'.$extension;
    copy($response->baseResponse->getFile()->getPathname(), $copy);

    return $copy;
}

test('owners export temp passwords as CSV or XLSX and skip staff without one', function (string $format) {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $ali = User::factory()->for($property)->withRole(DefaultRoleEnum::FrontDesk)->withTemporaryPassword('Teh-0042-Roti')->create(['name' => 'Ali', 'email' => 'ali@hotel.com']);

    $response = $this->actingAs($owner)
        ->post(route('settings.staff.temp-passwords.export', $property->code), ['user_ids' => [$ali->id, $owner->id], 'format' => $format])
        ->assertOk()
        ->assertDownload("temp-passwords-{$property->code}.{$format}")
        ->assertHeader('X-Skipped', '1');

    $rows = SimpleExcelReader::create(exportedFile($response, $format))->getRows()->all();

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'Property' => $property->name,
            'Name' => 'Ali',
            'Email' => 'ali@hotel.com',
            'Role' => 'Front desk',
            'Temp password' => 'Teh-0042-Roti',
            'Login URL' => route('login'),
        ]);

    $activity = Activity::query()->sole();
    expect($activity->event)->toBe('staff.temp-passwords-exported')
        ->and($activity->properties['format'])->toBe($format)
        ->and($activity->properties['user_ids'])->toBe([$ali->id])
        ->and(json_encode($activity->properties))->not->toContain('Teh-0042-Roti');
})->with(['csv', 'xlsx']);

test('owners export temp passwords as PDF slips', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $staff = User::factory()->count(9)->for($property)->withRole(DefaultRoleEnum::FrontDesk)->withTemporaryPassword()->create();

    $response = $this->actingAs($owner)
        ->post(route('settings.staff.temp-passwords.export', $property->code), ['user_ids' => $staff->modelKeys(), 'format' => 'pdf'])
        ->assertOk()
        ->assertDownload("temp-passwords-{$property->code}.pdf");

    expect(file_get_contents(exportedFile($response, 'pdf')))->toStartWith('%PDF')
        ->and(Activity::query()->sole()->properties['format'])->toBe('pdf');
});

test('an export with no usable temp password is refused and not logged', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $expired = User::factory()->for($property)->withRole(DefaultRoleEnum::FrontDesk)->withTemporaryPassword()->create();
    $expired->forceFill(['temporary_password_expires_at' => now()->subDay()])->save();

    $this->actingAs($owner)
        ->postJson(route('settings.staff.temp-passwords.export', $property->code), ['user_ids' => [$expired->id, $owner->id], 'format' => 'csv'])
        ->assertJsonValidationErrors(['user_ids' => 'None of the chosen staff has an unexpired temp password.']);

    expect(Activity::query()->count())->toBe(0);
});

test('exports need a file format, staff.manage and staff of the own property', function () {
    $owner = User::factory()->owner()->create();
    $frontDesk = User::factory()->for($owner->property)->withRole(DefaultRoleEnum::FrontDesk)->withTemporaryPassword()->create();
    $outsider = User::factory()->withRole(DefaultRoleEnum::FrontDesk)->withTemporaryPassword()->create();
    $url = route('settings.staff.temp-passwords.export', $owner->property->code);
    $plainFrontDesk = User::factory()->for($owner->property)->withRole(DefaultRoleEnum::FrontDesk)->create();

    $this->actingAs($plainFrontDesk)->postJson($url, ['user_ids' => [$frontDesk->id], 'format' => 'csv'])->assertForbidden();

    $this->actingAs($owner)->postJson($url, ['user_ids' => [$frontDesk->id], 'format' => 'message'])->assertJsonValidationErrors('format');
    $this->postJson($url, ['user_ids' => [$outsider->id], 'format' => 'csv'])->assertJsonValidationErrors('user_ids.0');

    expect(Activity::query()->count())->toBe(0);
});

test('the staff and bulk add pages offer the file formats to managers only', function () {
    $owner = User::factory()->owner()->create();
    $formats = [['value' => 'csv', 'label' => 'CSV'], ['value' => 'xlsx', 'label' => 'Excel (XLSX)'], ['value' => 'pdf', 'label' => 'PDF slips']];

    $this->actingAs($owner)->get(route('settings.staff.index', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('exportFormats', $formats));
    $this->get(route('settings.staff.bulk.create', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('exportFormats', $formats));

    $viewer = User::factory()->for($owner->property)->withRole(DefaultRoleEnum::Manager)->create();
    $viewer->roles->first()->revokePermissionTo('staff.manage');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($viewer)->get(route('settings.staff.index', $owner->property->code))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->where('exportFormats', []));
});
