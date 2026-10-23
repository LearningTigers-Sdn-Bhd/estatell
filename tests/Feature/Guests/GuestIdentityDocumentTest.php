<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\MediaDisk;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Storage::fake('local', ['serve' => true]);
});

function documentPayload(array $overrides = []): array
{
    return [
        'type' => 'mykad',
        'number' => '900101-14-1234',
        'issuing_country_code' => 'MY',
        'expires_on' => '2030-01-31',
        ...$overrides,
    ];
}

function guestWithDocument(User $user, array $attributes = []): array
{
    $guest = Guest::factory()->for($user->property)->create();
    $document = GuestIdentityDocument::factory()->for($guest)->create(['number' => '900101141234', ...$attributes]);

    return [$guest, $document];
}

function pdfScan(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF");
}

test('a document is added with its scans and the number is stored encrypted and searchable', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $guest = Guest::factory()->for($property)->create();

    $this->actingAs($owner)
        ->post(route('guests.documents.store', [$property->code, $guest]), documentPayload([
            'front_scan' => UploadedFile::fake()->image('front.jpg'),
            'back_scan' => pdfScan('back.pdf'),
        ]))
        ->assertRedirect(route('guests.documents.index', [$property->code, $guest]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'ID document added.']);

    $document = GuestIdentityDocument::query()->sole();

    expect($document->number)->toBe('900101141234')
        ->and(DB::table('guest_identity_documents')->value('number'))->not->toContain('900101141234')
        ->and(GuestIdentityDocument::query()->whereBlind('number', 'number_index', '900101-14-1234')->count())->toBe(1)
        ->and(GuestIdentityDocument::query()->whereBlind('number', 'number_index', '900101141235')->count())->toBe(0)
        ->and($document->getMedia('id_front'))->toHaveCount(1)
        ->and($document->getMedia('id_back'))->toHaveCount(1);

    $log = Activity::query()->where('event', 'guest.document_added')->sole();

    expect($log->properties['type'])->toBe('mykad')->and(json_encode($log->properties))->not->toContain('900101');
});

test('the number and files are validated', function () {
    $owner = User::factory()->owner()->create();
    $url = route('guests.documents.store', [$owner->property->code, Guest::factory()->for($owner->property)->create()]);

    $this->actingAs($owner);
    $this->post($url, documentPayload(['number' => '12345']))->assertSessionHasErrors('number');
    $this->post($url, documentPayload(['type' => 'passport', 'number' => 'A1']))->assertSessionHasErrors('number');
    $this->post($url, documentPayload(['type' => 'passport', 'number' => 'a 1234567']))->assertSessionHasNoErrors();
    expect(GuestIdentityDocument::query()->sole()->number)->toBe('A1234567');

    $this->post($url, documentPayload(['type' => 'bogus']))->assertSessionHasErrors('type');
    $this->post($url, documentPayload(['issuing_country_code' => 'ZZ']))->assertSessionHasErrors('issuing_country_code');
    $this->post($url, documentPayload(['expires_on' => 'soon']))->assertSessionHasErrors('expires_on');
    $this->post($url, documentPayload(['front_scan' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain')]))->assertSessionHasErrors('front_scan');
    $this->post($url, documentPayload(['back_scan' => UploadedFile::fake()->image('big.jpg')->size(10241)]))->assertSessionHasErrors('back_scan');
    expect(GuestIdentityDocument::query()->count())->toBe(1);
});

test('updating saves the fields, replaces a scan and removes the old file', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    [$guest, $document] = guestWithDocument($owner);
    $document->addMedia(UploadedFile::fake()->image('old-front.jpg'))->toMediaCollection('id_front');
    $document->addMedia(UploadedFile::fake()->image('old-back.jpg'))->toMediaCollection('id_back');
    $oldFront = $document->getFirstMedia('id_front');
    $oldPath = $oldFront->getPathRelativeToRoot();

    $this->actingAs($owner)
        ->put(route('guests.documents.update', [$property->code, $guest, $document]), documentPayload([
            'type' => 'passport',
            'number' => 'k1234567',
            'front_scan' => UploadedFile::fake()->image('new-front.jpg'),
            'remove_back_scan' => true,
        ]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'ID document saved.']);

    $document = GuestIdentityDocument::query()->sole();

    expect($document->number)->toBe('K1234567')
        ->and($document->getMedia('id_front'))->toHaveCount(1)
        ->and($document->getFirstMedia('id_front')->file_name)->toBe('new-front.jpg')
        ->and(Media::query()->whereKey($oldFront->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($oldPath))->toBeFalse()
        ->and($document->hasMedia('id_back'))->toBeFalse()
        ->and(GuestIdentityDocument::query()->whereBlind('number', 'number_index', 'k1234567')->count())->toBe(1)
        ->and(GuestIdentityDocument::query()->whereBlind('number', 'number_index', '900101141234')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'guest.document_updated')->sole()->properties['changed'])->toContain('type', 'id_front', 'id_back');
});

test('removing a document deletes its row, blind index and scan files', function () {
    $owner = User::factory()->owner()->create();
    [$guest, $document] = guestWithDocument($owner);
    $document->addMedia(UploadedFile::fake()->image('front.jpg'))->toMediaCollection('id_front');
    $path = $document->getFirstMedia('id_front')->getPathRelativeToRoot();

    $this->actingAs($owner)
        ->delete(route('guests.documents.destroy', [$owner->property->code, $guest, $document]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'ID document removed.']);

    expect(GuestIdentityDocument::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0)
        ->and(DB::table('blind_indexes')->count())->toBe(0)
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(Activity::query()->where('event', 'guest.document_removed')->count())->toBe(1);
});

test('a merged or anonymized guest cannot get or change documents', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $survivor = Guest::factory()->for($property)->create();
    $closed = Guest::factory()->for($property)->create(['anonymized_at' => now()]);
    $document = GuestIdentityDocument::factory()->for($closed)->create();

    $this->actingAs($owner)
        ->post(route('guests.documents.store', [$property->code, $closed]), documentPayload())
        ->assertInertiaFlash('toast.type', 'error');
    $this->put(route('guests.documents.update', [$property->code, $closed, $document]), documentPayload(['number' => '111111111111']))
        ->assertInertiaFlash('toast.type', 'error');

    expect(GuestIdentityDocument::query()->count())->toBe(1)
        ->and($document->refresh()->number)->not->toBe('111111111111')
        ->and($survivor->documents()->count())->toBe(0);
});

test('the Documents tab sends masked numbers and never the full number', function () {
    $owner = User::factory()->owner()->create();
    [$guest] = guestWithDocument($owner, ['expires_on' => '2020-01-01']);

    $response = $this->actingAs($owner)->get(route('guests.documents.index', [$owner->property->code, $guest]));

    $response->assertInertia(fn (Assert $page) => $page->component('guests/show')
        ->where('tab', 'documents')
        ->where('documents.0.maskedNumber', '•••• 1234')
        ->where('documents.0.number', null)
        ->where('documents.0.typeLabel', 'MyKad')
        ->where('documents.0.isExpired', true)
        ->where('canViewDocuments', true));

    expect($response->getContent())->not->toContain('900101141234')->not->toContain('900101-14-1234');
});

test('revealing a number and opening a scan are logged', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    [$guest, $document] = guestWithDocument($owner);
    $document->addMedia(UploadedFile::fake()->image('front.jpg'))->toMediaCollection('id_front');

    $this->actingAs($owner)
        ->postJson(route('guests.documents.reveal', [$property->code, $guest, $document]))
        ->assertOk()
        ->assertExactJson(['number' => '900101-14-1234']);
    expect(Activity::query()->where('event', 'guest.document_number_viewed')->count())->toBe(1);

    $this->get(route('guests.documents.scan', [$property->code, $guest, $document, 'front']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
    expect(Activity::query()->where('event', 'guest.document_scan_viewed')->sole()->properties['scan'])->toBe('id_front');

    $this->get(route('guests.documents.scan', [$property->code, $guest, $document, 'back']))->assertNotFound();
    $this->get(route('guests.documents.scan', [$property->code, $guest, $document, 'side']))->assertNotFound();
    expect(Activity::query()->where('event', 'guest.document_scan_viewed')->count())->toBe(1);
});

test('the documents tab never carries the full number and sends the sheet form options only to staff who can manage', function () {
    $owner = User::factory()->owner()->create();
    [$guest, $document] = guestWithDocument($owner);
    $url = route('guests.documents.index', [$owner->property->code, $guest]);

    $this->actingAs($owner)->get($url)
        ->assertInertia(fn (Assert $page) => $page->where('documents.0.number', null)
            ->where('documents.0.maskedNumber', fn ($masked) => $masked !== '900101-14-1234')
            ->has('documentFormOptions.types')
            ->has('documentFormOptions.countries'));

    $this->actingAs(User::factory()->for($owner->property)->withPermissions(['guests.view-documents'])->create())->get($url)
        ->assertInertia(fn (Assert $page) => $page->where('documentFormOptions', null));
});

test('new scans go to the disk chosen in platform settings and older files keep theirs', function () {
    Storage::fake('r2');
    config(['filesystems.disks.r2.key' => 'k', 'filesystems.disks.r2.secret' => 's', 'filesystems.disks.r2.bucket' => 'b', 'filesystems.disks.r2.endpoint' => 'https://r2.example.test']);

    $owner = User::factory()->owner()->create();
    [, $document] = guestWithDocument($owner);
    $document->addMedia(UploadedFile::fake()->image('first.jpg'))->toMediaCollection('id_front');

    DB::table('platform_settings')->update(['media_disk' => MediaDisk::R2->value]);
    Cache::forget(PlatformSettings::CACHE_KEY);

    $document = GuestIdentityDocument::query()->sole();
    $document->addMedia(UploadedFile::fake()->image('second.jpg'))->toMediaCollection('id_back');

    expect($document->getFirstMedia('id_front')->disk)->toBe('local')
        ->and($document->getFirstMedia('id_back')->disk)->toBe('r2');
});

test('the guest list finds an exact ID number only for staff who may view documents', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    [$guest] = guestWithDocument($owner);
    Guest::factory()->for($property)->create();
    $viewer = User::factory()->for($property)->withPermissions(['guests.view'])->create();
    $url = route('guests.index', $property->code).'?filter[search]=900101-14-1234';

    $this->actingAs($owner)->get($url)
        ->assertInertia(fn (Assert $page) => $page->has('guests.data', 1)->where('guests.data.0.id', $guest->id));

    $this->get(route('guests.index', $property->code).'?filter[search]=900101141235')
        ->assertInertia(fn (Assert $page) => $page->has('guests.data', 0));

    $this->actingAs($viewer)->get($url)
        ->assertInertia(fn (Assert $page) => $page->has('guests.data', 0));
});

test('each document permission is enforced', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    [$guest, $document] = guestWithDocument($owner);
    $routes = fn () => [
        ['get', route('guests.documents.index', [$property->code, $guest])],
        ['post', route('guests.documents.reveal', [$property->code, $guest, $document])],
        ['get', route('guests.documents.scan', [$property->code, $guest, $document, 'front'])],
        ['post', route('guests.documents.store', [$property->code, $guest])],
        ['put', route('guests.documents.update', [$property->code, $guest, $document])],
        ['delete', route('guests.documents.destroy', [$property->code, $guest, $document])],
    ];

    foreach ([DefaultRoleEnum::Finance, DefaultRoleEnum::Housekeeping] as $role) {
        $user = User::factory()->for($property)->withRole($role)->create();
        $this->actingAs($user);
        foreach ($routes() as [$method, $url]) {
            $this->{$method}($url)->assertForbidden();
        }
    }

    $this->actingAs(User::factory()->for($property)->withPermissions(['guests.view-documents'])->create());
    $this->get(route('guests.documents.index', [$property->code, $guest]))->assertOk();
    $this->postJson(route('guests.documents.reveal', [$property->code, $guest, $document]))->assertOk();
    foreach (array_slice($routes(), 3) as [$method, $url]) {
        $this->{$method}($url)->assertForbidden();
    }

    $frontDesk = User::factory()->for($property)->withRole(DefaultRoleEnum::FrontDesk)->create();
    $this->actingAs($frontDesk)->get(route('guests.documents.index', [$property->code, $guest]))
        ->assertInertia(fn (Assert $page) => $page->has('documentFormOptions.types'));
});

test('another property\'s guest or document returns 404', function () {
    $owner = User::factory()->owner()->create();
    $code = $owner->property->code;
    $mine = Guest::factory()->for($owner->property)->create();
    $foreign = Guest::factory()->create();
    $foreignDocument = GuestIdentityDocument::factory()->for($foreign)->create();
    $mineButWrongGuest = GuestIdentityDocument::factory()->for($mine)->create();

    $this->actingAs($owner);
    $this->get(route('guests.documents.index', [$code, $foreign]))->assertNotFound();
    $this->post(route('guests.documents.store', [$code, $foreign]), documentPayload())->assertNotFound();
    $this->postJson(route('guests.documents.reveal', [$code, $mine, $foreignDocument]))->assertNotFound();
    $this->delete(route('guests.documents.destroy', [$code, $mine, $foreignDocument]))->assertNotFound();
    $this->get(route('guests.documents.scan', [$code, $foreign, $foreignDocument, 'front']))->assertNotFound();
    expect($mineButWrongGuest->refresh()->guest_id)->toBe($mine->id);
});
