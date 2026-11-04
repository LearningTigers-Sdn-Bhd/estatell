<?php

use App\Models\Property;
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
function propertyPhotoUrl(Property $property, string $name = '', array $parameters = []): string
{
    return route('settings.photos'.($name === '' ? '' : '.'.$name), ['property' => $property->code, ...$parameters]);
}

test('staff upload photos to the public disk and the tab lists them with public urls', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;

    $this->actingAs($owner)
        ->post(propertyPhotoUrl($property, 'store'), ['photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png')]])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success');

    $photos = $property->refresh()->getMedia(Property::PHOTOS);

    expect($photos)->toHaveCount(2)
        ->and($photos->first()->disk)->toBe('public')
        ->and($photos->first()->file_name)->toMatch('/^[0-9a-f-]{36}\.jpg$/')
        ->and(Activity::query()->where('event', 'property.updated')->count())->toBe(1);

    Storage::disk('public')->assertExists($photos->first()->getPathRelativeToRoot());

    $this->actingAs($owner)
        ->get(propertyPhotoUrl($property))
        ->assertInertia(fn (Assert $page) => $page->component('settings/edit')
            ->where('tab', 'photos')
            ->has('photos', 2)
            ->has('photos.0.thumbUrl')
            ->where('photoLimit', 20));
});

test('uploads refuse non-images, big files and more than the limit', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;

    $this->actingAs($owner)->post(propertyPhotoUrl($property, 'store'), ['photos' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]])->assertSessionHasErrors('photos.0');
    $this->actingAs($owner)->post(propertyPhotoUrl($property, 'store'), ['photos' => [UploadedFile::fake()->image('big.jpg')->size(Property::PHOTO_MAX_KB + 1)]])->assertSessionHasErrors('photos.0');

    $files = fn (int $count): array => array_map(fn (int $i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, $count));

    $this->actingAs($owner)->post(propertyPhotoUrl($property, 'store'), ['photos' => $files(Property::PHOTO_LIMIT)])->assertSessionHasNoErrors();
    $logged = Activity::query()->count();

    $this->actingAs($owner)->post(propertyPhotoUrl($property, 'store'), ['photos' => $files(1)])->assertInertiaFlash('toast.type', 'error');

    expect($property->refresh()->getMedia(Property::PHOTOS))->toHaveCount(Property::PHOTO_LIMIT)
        ->and(Activity::query()->count())->toBe($logged);
});

test('staff reorder and remove photos, and ids of other properties are refused', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $otherOwner = User::factory()->owner()->create();

    $this->actingAs($owner)->post(propertyPhotoUrl($property, 'store'), ['photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]]);
    $this->actingAs($otherOwner)->post(propertyPhotoUrl($otherOwner->property, 'store'), ['photos' => [UploadedFile::fake()->image('c.jpg')]]);

    [$first, $second] = $property->refresh()->getMedia(Property::PHOTOS)->pluck('id')->all();
    $foreign = $otherOwner->property->refresh()->getFirstMedia(Property::PHOTOS)->id;

    $this->actingAs($owner)->put(propertyPhotoUrl($property, 'reorder'), ['order' => [$second, $first]])->assertSessionDoesntHaveErrors();
    expect($property->refresh()->getMedia(Property::PHOTOS)->pluck('id')->all())->toBe([$second, $first]);

    $this->actingAs($owner)->put(propertyPhotoUrl($property, 'reorder'), ['order' => [$first, $foreign]])->assertInertiaFlash('toast.type', 'error');
    expect($property->refresh()->getMedia(Property::PHOTOS)->pluck('id')->all())->toBe([$second, $first]);

    $this->actingAs($owner)->delete(propertyPhotoUrl($property, 'destroy', ['photo' => $foreign]))->assertInertiaFlash('toast.type', 'error');
    expect($otherOwner->property->refresh()->getMedia(Property::PHOTOS))->toHaveCount(1);

    $this->actingAs($owner)->delete(propertyPhotoUrl($property, 'destroy', ['photo' => $first]))->assertInertiaFlash('toast.type', 'success');
    expect($property->refresh()->getMedia(Property::PHOTOS)->pluck('id')->all())->toBe([$second]);
});

test('the album works without the booking engine, and the logo stays separate', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;

    $property->addMedia(UploadedFile::fake()->image('logo.png'))->toMediaCollection(Property::LOGO);

    $this->actingAs($owner)->post(propertyPhotoUrl($property, 'store'), ['photos' => [UploadedFile::fake()->image('a.jpg')]])->assertSessionHasNoErrors();

    $property->refresh();

    expect($property->getMedia(Property::PHOTOS))->toHaveCount(1)
        ->and($property->getMedia(Property::LOGO))->toHaveCount(1);
});

test('photos need the settings manage permission and stay inside the property', function () {
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    $staff = User::factory()->for($property)->withPermissions(['rooms.view'])->create();
    $outsider = User::factory()->owner()->create();

    $this->actingAs($staff)->get(propertyPhotoUrl($property))->assertForbidden();
    $this->actingAs($staff)->post(propertyPhotoUrl($property, 'store'), ['photos' => [UploadedFile::fake()->image('a.jpg')]])->assertForbidden();

    $this->actingAs($outsider)->get(propertyPhotoUrl($property))->assertNotFound();
});
