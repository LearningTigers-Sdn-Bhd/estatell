<?php

use App\Actions\Onboarding\Wastays\RecordImportLink;
use App\Models\ImportLink;
use App\Models\Property;
use App\Services\Onboarding\Wastays\ImportLinks;
use App\Services\Onboarding\Wastays\WastaysBundle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

const WASTAYS_KEY = 'imports/test-hotel-bundle/2026-10-22.zip';

beforeEach(function () {
    Storage::fake('r2');
    app(Filesystem::class)->deleteDirectory(storage_path('app/private/imports/test-hotel-bundle'));
});

afterEach(function () {
    app(Filesystem::class)->deleteDirectory(storage_path('app/private/imports/test-hotel-bundle'));
});

/**
 * @param  array<string, mixed>  $overrides  manifest keys to replace
 * @param  array<string, string>  $csvs  file name => contents, written to the zip
 */
function putWastaysZip(array $overrides = [], ?array $csvs = null, bool $listAllFiles = true): string
{
    $csvs ??= [
        'bookings.csv' => "id,guest_name,notes\n1,Aisha,\n2,\"Lee, Tan\",late arrival\n",
        'payments.csv' => "id,amount\n10,120.00\n",
    ];

    $files = [];
    foreach ($csvs as $name => $contents) {
        $files[$name] = ['rows' => max(substr_count(rtrim($contents, "\n"), "\n"), 0), 'sha256' => hash('sha256', $contents)];
    }
    if (! $listAllFiles) {
        unset($files['payments.csv']);
    }

    $manifest = array_merge([
        'format_version' => 1,
        'hotel_id' => 3,
        'hotel_code' => 'GEH',
        'hotel_name' => 'Grand Eastern Hotel',
        'exported_at' => '2026-10-22T10:15:00+08:00',
        'first_audit_date' => '2026-08-13',
        'last_audit_date' => '2026-10-21',
        'files' => $files,
    ], $overrides);

    $path = tempnam(sys_get_temp_dir(), 'wxzip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode($manifest));
    foreach ($csvs as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();

    Storage::disk('r2')->put(WASTAYS_KEY, file_get_contents($path));
    unlink($path);

    return hash('sha256', Storage::disk('r2')->get(WASTAYS_KEY));
}

function wastaysTarget(): string
{
    return storage_path('app/private/imports/test-hotel-bundle/2026-10-22');
}

it('fetches a good bundle, prints a summary and reads rows', function () {
    $sha = putWastaysZip();

    $this->artisan('wastays:fetch', ['key' => WASTAYS_KEY, '--sha256' => $sha])
        ->expectsOutputToContain('Grand Eastern Hotel')
        ->expectsOutputToContain('2026-08-13 to 2026-10-21')
        ->assertSuccessful();

    $bundle = WastaysBundle::open(wastaysTarget());

    expect($bundle->manifest->hotelCode)->toBe('GEH')
        ->and($bundle->manifest->files['bookings.csv']['rows'])->toBe(2)
        ->and($bundle->rows('bookings')->all())->toBe([
            ['id' => '1', 'guest_name' => 'Aisha', 'notes' => null],
            ['id' => '2', 'guest_name' => 'Lee, Tan', 'notes' => 'late arrival'],
        ]);
});

it('uses the sha256 object next to the zip when no option is given', function () {
    $sha = putWastaysZip();
    Storage::disk('r2')->put(WASTAYS_KEY.'.sha256', $sha."  2026-10-22.zip\n");

    $this->artisan('wastays:fetch', ['key' => WASTAYS_KEY])->assertSuccessful();

    Storage::disk('r2')->put(WASTAYS_KEY.'.sha256', str_repeat('0', 64));
    $this->artisan('wastays:fetch', ['key' => WASTAYS_KEY])->assertFailed();
});

it('fetches from another disk with --disk', function () {
    $sha = putWastaysZip();
    Storage::fake('local')->put(WASTAYS_KEY, Storage::disk('r2')->get(WASTAYS_KEY));
    Storage::disk('r2')->delete(WASTAYS_KEY);

    $this->artisan('wastays:fetch', ['key' => WASTAYS_KEY, '--sha256' => $sha, '--disk' => 'local'])->assertSuccessful();

    expect(WastaysBundle::open(storage_path('app/private/imports/test-hotel-bundle/2026-10-22'))->rows('payments')->all())
        ->toBe([['id' => '10', 'amount' => '120.00']]);
});

it('refuses a wrong zip sha256', function () {
    putWastaysZip();

    $this->artisan('wastays:fetch', ['key' => WASTAYS_KEY, '--sha256' => str_repeat('0', 64)])
        ->expectsOutputToContain('zip SHA-256')
        ->assertFailed();

    expect(is_dir(wastaysTarget()))->toBeFalse();
});

it('refuses a file with a wrong sha256 and leaves nothing extracted', function () {
    putWastaysZip(['files' => [
        'bookings.csv' => ['rows' => 2, 'sha256' => str_repeat('a', 64)],
        'payments.csv' => ['rows' => 1, 'sha256' => hash('sha256', "id,amount\n10,120.00\n")],
    ]]);

    $this->artisan('wastays:fetch', ['key' => WASTAYS_KEY])
        ->expectsOutputToContain('bookings.csv has a different SHA-256')
        ->assertFailed();

    expect(is_dir(wastaysTarget()))->toBeFalse();
});

it('refuses a wrong row count and leaves nothing extracted', function () {
    $csv = "id,amount\n10,120.00\n";
    putWastaysZip(['files' => [
        'bookings.csv' => ['rows' => 2, 'sha256' => hash('sha256', "id,guest_name,notes\n1,Aisha,\n2,\"Lee, Tan\",late arrival\n")],
        'payments.csv' => ['rows' => 5, 'sha256' => hash('sha256', $csv)],
    ]]);

    $this->artisan('wastays:fetch', ['key' => WASTAYS_KEY])
        ->expectsOutputToContain('payments.csv has 1 rows, the manifest says 5')
        ->assertFailed();

    expect(is_dir(wastaysTarget()))->toBeFalse();
});

it('refuses a listed file that is missing and an extra csv that is not listed', function () {
    putWastaysZip(['files' => [
        'bookings.csv' => ['rows' => 2, 'sha256' => hash('sha256', "id,guest_name,notes\n1,Aisha,\n2,\"Lee, Tan\",late arrival\n")],
        'payments.csv' => ['rows' => 1, 'sha256' => hash('sha256', "id,amount\n10,120.00\n")],
        'folios.csv' => ['rows' => 0, 'sha256' => str_repeat('b', 64)],
    ]]);

    $this->artisan('wastays:fetch', ['key' => WASTAYS_KEY])
        ->expectsOutputToContain('folios.csv is listed in the manifest but missing')
        ->assertFailed();
    expect(is_dir(wastaysTarget()))->toBeFalse();

    putWastaysZip(listAllFiles: false);

    $this->artisan('wastays:fetch', ['key' => WASTAYS_KEY])
        ->expectsOutputToContain('payments.csv is in the bundle but not in the manifest')
        ->assertFailed();
    expect(is_dir(wastaysTarget()))->toBeFalse();
});

it('refuses an unsupported format version', function () {
    putWastaysZip(['format_version' => 2]);

    $this->artisan('wastays:fetch', ['key' => WASTAYS_KEY])->assertFailed();

    expect(is_dir(wastaysTarget()))->toBeFalse();
});

it('purges one bundle or all bundles without touching r2', function () {
    putWastaysZip();
    $this->artisan('wastays:fetch', ['key' => WASTAYS_KEY])->assertSuccessful();

    $this->artisan('wastays:purge', ['bundle' => 'test-hotel-bundle/2026-10-22'])->assertSuccessful();
    expect(is_dir(wastaysTarget()))->toBeFalse();
    Storage::disk('r2')->assertExists(WASTAYS_KEY);

    $this->artisan('wastays:fetch', ['key' => WASTAYS_KEY])->assertSuccessful();
    $this->artisan('wastays:purge', ['--all' => true])->assertSuccessful();
    expect(is_dir(wastaysTarget()))->toBeFalse();

    $this->artisan('wastays:purge', ['bundle' => '../../../config'])->assertFailed();
});

it('keeps one import link per property, source table and source id', function () {
    $property = Property::factory()->create();
    ImportLink::factory()->for($property)->create(['source_table' => 'bookings', 'source_id' => '7']);

    ImportLink::factory()->for($property)->create(['source_table' => 'bookings', 'source_id' => '7']);
})->throws(UniqueConstraintViolationException::class);

it('records a link once and finds its target', function () {
    $property = Property::factory()->create();
    $other = Property::factory()->create();

    app(RecordImportLink::class)($property, 'hotels', 8, $other);
    app(RecordImportLink::class)($property, 'hotels', '8', $property);

    expect(ImportLink::query()->count())->toBe(1)
        ->and(app(ImportLinks::class)->targetId($property, 'hotels', 8))->toBe($other->id)
        ->and(app(ImportLinks::class)->has($other, 'hotels', 8))->toBeFalse();
});
