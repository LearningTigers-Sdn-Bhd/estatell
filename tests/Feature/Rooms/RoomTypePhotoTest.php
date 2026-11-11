<?php

use App\Models\RoomType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('public');
});

/**
 * @param  array<string, mixed>  $parameters
 */
function photoUrl(RoomType $roomType, string $name = 'edit', array $parameters = []): string
{
    return route('settings.room-types.photos.'.$name, ['property' => $roomType->property->code, 'roomType' => $roomType->id, ...$parameters]);
}

test('staff upload photos to the public disk and the tab lists them with public urls', function () {
    $owner = User::factory()->owner()->create();
    $roomType = RoomType::factory()->for($owner->property)->create();

    $this->actingAs($owner)
        ->post(photoUrl($roomType, 'store'), ['photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png')]])
        ->assertSessionHasNoErrors();

    $photos = $roomType->refresh()->getMedia(RoomType::PHOTOS);

    expect($photos)->toHaveCount(2)
        ->and($photos->first()->disk)->toBe('public')
        ->and($photos->first()->file_name)->toMatch('/^[0-9a-f-]{36}\.jpg$/')
        ->and(Activity::query()->where('event', 'room_type.updated')->count())->toBe(1);

    Storage::disk('public')->assertExists($photos->first()->getPathRelativeToRoot());

    $this->actingAs($owner)
        ->get(photoUrl($roomType))
        ->assertInertia(fn (Assert $page) => $page->component('settings/room-types/photos')
            ->has('photos', 2)
            ->has('photos.0.name')
            ->where('photoLimit', RoomType::PHOTO_LIMIT));
});

test('uploads refuse non-images, big files, too many photos and archived room types', function () {
    $owner = User::factory()->owner()->create();
    $roomType = RoomType::factory()->for($owner->property)->create();

    $this->actingAs($owner)->post(photoUrl($roomType, 'store'), ['photos' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]])->assertSessionHasErrors('photos.0');
    $this->actingAs($owner)->post(photoUrl($roomType, 'store'), ['photos' => [UploadedFile::fake()->image('big.jpg')->size(RoomType::PHOTO_MAX_KB + 1)]])->assertSessionHasErrors('photos.0');

    $this->actingAs($owner)->post(photoUrl($roomType, 'store'), ['photos' => [UploadedFile::fake()->image('page.html')]])->assertSessionHasErrors('photos.0');

    $files = fn (int $count): array => array_map(fn (int $i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, $count));

    $this->actingAs($owner)->post(photoUrl($roomType, 'store'), ['photos' => $files(RoomType::PHOTO_LIMIT)])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post(photoUrl($roomType, 'store'), ['photos' => $files(1)])->assertInertiaFlash('toast.type', 'error');

    $archived = RoomType::factory()->for($owner->property)->archived()->create();
    $this->actingAs($owner)->post(photoUrl($archived, 'store'), ['photos' => $files(1)])->assertInertiaFlash('toast.type', 'error');

    expect($roomType->refresh()->getMedia(RoomType::PHOTOS))->toHaveCount(RoomType::PHOTO_LIMIT)
        ->and($archived->refresh()->getMedia(RoomType::PHOTOS))->toHaveCount(0);
});

test('staff reorder and remove photos, and ids of other room types are refused', function () {
    $owner = User::factory()->owner()->create();
    $roomType = RoomType::factory()->for($owner->property)->create();
    $other = RoomType::factory()->for($owner->property)->create();

    $this->actingAs($owner)->post(photoUrl($roomType, 'store'), ['photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]]);
    $this->actingAs($owner)->post(photoUrl($other, 'store'), ['photos' => [UploadedFile::fake()->image('c.jpg')]]);

    [$first, $second] = $roomType->refresh()->getMedia(RoomType::PHOTOS)->pluck('id')->all();
    $foreign = $other->refresh()->getFirstMedia(RoomType::PHOTOS)->id;

    $this->actingAs($owner)->put(photoUrl($roomType, 'reorder'), ['order' => [$second, $first]])->assertSessionDoesntHaveErrors();
    expect($roomType->refresh()->getMedia(RoomType::PHOTOS)->pluck('id')->all())->toBe([$second, $first]);

    $this->actingAs($owner)->put(photoUrl($roomType, 'reorder'), ['order' => [$first, $foreign]])->assertInertiaFlash('toast.type', 'error');
    expect($roomType->refresh()->getMedia(RoomType::PHOTOS)->pluck('id')->all())->toBe([$second, $first]);

    $this->actingAs($owner)->delete(photoUrl($roomType, 'destroy', ['photo' => $foreign]))->assertInertiaFlash('toast.type', 'error');
    expect($other->refresh()->getMedia(RoomType::PHOTOS))->toHaveCount(1);

    $this->actingAs($owner)->delete(photoUrl($roomType, 'destroy', ['photo' => $first]))->assertInertiaFlash('toast.type', 'success');
    expect($roomType->refresh()->getMedia(RoomType::PHOTOS)->pluck('id')->all())->toBe([$second]);
});

test('photos need the rooms manage permission and stay inside the property', function () {
    $owner = User::factory()->owner()->create();
    $roomType = RoomType::factory()->for($owner->property)->create();
    $viewer = User::factory()->for($owner->property)->withPermissions(['rooms.view'])->create();
    $outsider = User::factory()->owner()->create();

    $this->actingAs($viewer)->get(photoUrl($roomType))->assertOk();
    $this->actingAs($viewer)->post(photoUrl($roomType, 'store'), ['photos' => [UploadedFile::fake()->image('a.jpg')]])->assertForbidden();

    $this->actingAs($outsider)->get(photoUrl($roomType))->assertNotFound();
});
