<?php

use App\Actions\Guests\PurgeExpiredIdentityScans;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

function propertyWithRetention(?int $days): Property
{
    $property = User::factory()->owner()->create()->property;
    PropertySetting::factory()->for($property)->create(['id_document_retention_days' => $days]);

    return $property;
}

function documentWithScans(Property $property, string $uploadedAt, string $number = '900101141234'): GuestIdentityDocument
{
    $document = GuestIdentityDocument::factory()->for(Guest::factory()->for($property)->create())->create(['number' => $number]);

    foreach (['id_front', 'id_back'] as $collection) {
        $media = $document->addMedia(UploadedFile::fake()->image("{$collection}.jpg"))->toMediaCollection($collection);
        $media->forceFill(['created_at' => $uploadedAt])->save();
    }

    return $document;
}

test('scans older than the retention are deleted and newer ones stay', function () {
    $property = propertyWithRetention(30);
    $old = documentWithScans($property, '2026-09-01 10:00:00');
    $recent = documentWithScans($property, '2026-09-25 10:00:00', '900202252345');
    $path = $old->getFirstMedia('id_front')->getPathRelativeToRoot();

    expect(app(PurgeExpiredIdentityScans::class)())->toBe(2);

    expect($old->refresh()->media()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and($recent->refresh()->media()->count())->toBe(2)
        ->and(GuestIdentityDocument::query()->count())->toBe(2)
        ->and($old->number)->toBe('900101141234')
        ->and(GuestIdentityDocument::query()->whereBlind('number', 'number_index', '900101141234')->count())->toBe(1);

    $log = Activity::query()->where('event', 'guest.document_scans_purged')->sole();

    expect($log->subject_id)->toBe($old->guest_id)
        ->and($log->properties['scans_deleted'])->toBe(2)
        ->and($log->properties['document_ids'])->toBe([$old->id])
        ->and($log->properties['retention_days'])->toBe(30)
        ->and(json_encode($log->properties))->not->toContain('900101');
});

test('a scan exactly at the retention age stays and one day older goes', function () {
    $property = propertyWithRetention(30);
    $edge = documentWithScans($property, '2026-09-10 12:00:00');
    $over = documentWithScans($property, '2026-09-10 11:59:59', '900202252345');

    app(PurgeExpiredIdentityScans::class)();

    expect($edge->refresh()->media()->count())->toBe(2)->and($over->refresh()->media()->count())->toBe(0);
});

test('a property without a retention value keeps its scans and each property uses its own value', function () {
    $keeper = propertyWithRetention(null);
    $short = propertyWithRetention(7);
    $long = propertyWithRetention(365);
    $kept = documentWithScans($keeper, '2020-01-01 10:00:00');
    $purged = documentWithScans($short, '2026-09-01 10:00:00');
    $stays = documentWithScans($long, '2026-09-01 10:00:00');

    expect(app(PurgeExpiredIdentityScans::class)())->toBe(2)
        ->and($kept->refresh()->media()->count())->toBe(2)
        ->and($purged->refresh()->media()->count())->toBe(0)
        ->and($stays->refresh()->media()->count())->toBe(2);
});

test('running it again changes nothing and other media is left alone', function () {
    $property = propertyWithRetention(30);
    documentWithScans($property, '2026-09-01 10:00:00');
    $guest = Guest::factory()->for($property)->create();
    $other = $guest->documents()->create(['type' => 'passport', 'number' => 'A1234567']);
    $other->addMedia(UploadedFile::fake()->image('x.jpg'))->toMediaCollection('some_other_collection');
    Media::query()->where('collection_name', 'some_other_collection')->update(['created_at' => '2020-01-01 00:00:00']);
    $action = app(PurgeExpiredIdentityScans::class);

    expect($action())->toBe(2)
        ->and($action())->toBe(0)
        ->and(Media::query()->where('collection_name', 'some_other_collection')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'guest.document_scans_purged')->count())->toBe(1);
});

test('one property failing does not stop the others', function () {
    $broken = propertyWithRetention(30);
    $fine = propertyWithRetention(30);
    $brokenDocument = documentWithScans($broken, '2026-09-01 10:00:00');
    $fineDocument = documentWithScans($fine, '2026-09-01 10:00:00', '900202252345');
    $brokenDocument->getFirstMedia('id_front')->forceFill(['disk' => 'missing_disk'])->save();

    expect(fn () => app(PurgeExpiredIdentityScans::class)())->not->toThrow(Throwable::class);
    expect($fineDocument->refresh()->media()->count())->toBe(0);
});

test('the purge is scheduled every night on one server', function () {
    $event = collect(app(Schedule::class)->events())->first(fn (Event $event): bool => $event->description === 'purge-expired-identity-scans');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 3 * * *')
        ->and($event->onOneServer)->toBeTrue();
});
