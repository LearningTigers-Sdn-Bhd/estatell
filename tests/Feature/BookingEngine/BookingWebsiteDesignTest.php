<?php

use App\Actions\BookingEngine\SetUpBookingSite;
use App\Enums\BookingEngineMode;
use App\Enums\PermissionEnum;
use App\Features\BookingEngine;
use App\Models\Property;
use App\Models\PropertyBookingSite;
use App\Models\SiteTemplate;
use App\Models\SiteTemplateSlot;
use App\Models\User;
use App\Services\BookingEngine\BookingSiteChecklist;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

/**
 * An owner with the Booking engine on, and the property's booking site.
 *
 * @return array{owner: User, property: Property, site: PropertyBookingSite}
 */
function designSetup(): array
{
    Storage::fake('public');
    $owner = User::factory()->owner()->create();
    $property = $owner->property;
    Feature::for($property)->activate(BookingEngine::class);

    return ['owner' => $owner, 'property' => $property, 'site' => app(SetUpBookingSite::class)($property)];
}

/**
 * A template with a required hero slot and an optional logo slot.
 */
function templateWithSlots(array $attributes = []): SiteTemplate
{
    $template = SiteTemplate::factory()->create($attributes);
    SiteTemplateSlot::factory()->required()->create(['site_template_id' => $template->id, 'key' => 'hero', 'label' => 'Hero image']);
    SiteTemplateSlot::factory()->create(['site_template_id' => $template->id, 'key' => 'logo', 'label' => 'Logo']);

    return $template;
}

test('the design tab lists the templates the property can pick, with the slots of the saved one', function () {
    ['owner' => $owner, 'property' => $property, 'site' => $site] = designSetup();
    $mine = templateWithSlots(['name' => 'Island resort']);
    SiteTemplate::factory()->draft()->create(['name' => 'Draft one']);
    SiteTemplate::factory()->retired()->create(['name' => 'Retired one']);
    SiteTemplate::factory()->custom()->create(['name' => 'Someone else']);
    SiteTemplate::factory()->custom($property)->create(['name' => 'Our design']);
    $site->update(['site_template_id' => $mine->id]);

    $this->actingAs($owner)->get(route('settings.booking-website.design', $property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/booking-website/design')
            ->where('design.siteTemplateId', $mine->id)
            ->has('design.templates', 2)
            ->where('design.templates.0.label', 'Island resort')
            ->where('design.templates.1.label', 'Our design (custom design)')
            ->has('design.slots', 2)
            ->where('design.slots.0.key', 'hero')
            ->where('design.slots.0.url', null));
});

test('a retired template stays in the list of the site that uses it', function () {
    ['owner' => $owner, 'property' => $property, 'site' => $site] = designSetup();
    $retired = templateWithSlots(['name' => 'Old look', 'status' => 'retired']);
    $site->update(['site_template_id' => $retired->id]);

    $this->actingAs($owner)->get(route('settings.booking-website.design', $property))
        ->assertInertia(fn (Assert $page) => $page->has('design.templates', 1)->where('design.templates.0.label', 'Old look'));

    $this->actingAs($owner)->put(route('settings.booking-website.design.update', $property), ['site_template_id' => $retired->id, 'main_color' => '#0f766e'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Design saved.']);

    expect($site->refresh()->main_color)->toBe('#0F766E');
});

test('the owner picks a template and a colour and the change is logged', function () {
    ['owner' => $owner, 'property' => $property, 'site' => $site] = designSetup();
    $template = templateWithSlots();

    $this->actingAs($owner)->put(route('settings.booking-website.design.update', $property), ['site_template_id' => $template->id, 'main_color' => '#0F766E'])
        ->assertRedirect(route('settings.booking-website.design', $property))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Design saved.']);

    expect($site->refresh()->site_template_id)->toBe($template->id)
        ->and($site->main_color)->toBe('#0F766E');
    expect(Activity::query()->where('event', 'booking-website.template-changed')->count())->toBe(1);
});

test('a draft, retired or another property\'s custom template cannot be picked', function () {
    ['owner' => $owner, 'property' => $property, 'site' => $site] = designSetup();

    foreach ([SiteTemplate::factory()->draft()->create(), SiteTemplate::factory()->retired()->create(), SiteTemplate::factory()->custom()->create()] as $template) {
        $this->actingAs($owner)->put(route('settings.booking-website.design.update', $property), ['site_template_id' => $template->id])
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'That template is not available. Choose another one.']);
    }

    expect($site->refresh()->site_template_id)->toBeNull()
        ->and(Activity::query()->where('event', 'booking-website.template-changed')->count())->toBe(0);
});

test('the colour must be a six digit hex value', function () {
    ['owner' => $owner, 'property' => $property] = designSetup();

    $this->actingAs($owner)->put(route('settings.booking-website.design.update', $property), ['main_color' => 'teal'])
        ->assertSessionHasErrors('main_color');
});

test('an image goes into a slot, replaces the one there and carries over to another template with the same slot', function () {
    ['owner' => $owner, 'property' => $property, 'site' => $site] = designSetup();
    $first = templateWithSlots();
    $second = SiteTemplate::factory()->create();
    SiteTemplateSlot::factory()->required()->create(['site_template_id' => $second->id, 'key' => 'hero', 'label' => 'Main picture']);
    $site->update(['site_template_id' => $first->id]);

    $upload = fn () => $this->actingAs($owner)->post(route('settings.booking-website.design.images.store', [$property, 'hero']), ['image' => UploadedFile::fake()->image('beach.jpg')]);

    $upload()->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Image saved.']);
    $upload();

    expect($site->refresh()->getMedia(PropertyBookingSite::IMAGES))->toHaveCount(1)
        ->and($site->getFirstMedia(PropertyBookingSite::IMAGES)->getCustomProperty('slot'))->toBe('hero');

    $this->actingAs($owner)->put(route('settings.booking-website.design.update', $property), ['site_template_id' => $second->id]);

    $this->actingAs($owner)->get(route('settings.booking-website.design', $property))
        ->assertInertia(fn (Assert $page) => $page
            ->where('design.slots.0.label', 'Main picture')
            ->whereNot('design.slots.0.url', null));
});

test('an image needs a real slot and a valid file', function () {
    ['owner' => $owner, 'property' => $property, 'site' => $site] = designSetup();
    $site->update(['site_template_id' => templateWithSlots()->id]);
    $url = fn (string $slot) => route('settings.booking-website.design.images.store', [$property, $slot]);

    $this->actingAs($owner)->post($url('missing'), ['image' => UploadedFile::fake()->image('a.jpg')])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The template has no such image slot. Reload the page and try again.']);
    $this->actingAs($owner)->post($url('hero'), ['image' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('image');
    $this->actingAs($owner)->post($url('hero'), ['image' => UploadedFile::fake()->image('big.jpg')->size(PropertyBookingSite::IMAGE_MAX_KB + 1)])
        ->assertSessionHasErrors('image');

    expect($site->refresh()->getMedia(PropertyBookingSite::IMAGES))->toHaveCount(0);
});

test('removing an image empties the slot and a second removal is refused', function () {
    ['owner' => $owner, 'property' => $property, 'site' => $site] = designSetup();
    $site->update(['site_template_id' => templateWithSlots()->id]);
    $this->actingAs($owner)->post(route('settings.booking-website.design.images.store', [$property, 'logo']), ['image' => UploadedFile::fake()->image('logo.png')]);
    $remove = fn () => $this->actingAs($owner)->delete(route('settings.booking-website.design.images.destroy', [$property, 'logo']));

    $remove()->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Image removed.']);
    $remove()->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'The image could not be removed. Reload the page and try again.']);

    expect($site->refresh()->getMedia(PropertyBookingSite::IMAGES))->toHaveCount(0);
});

test('choosing from the album copies the photo into the slot, and album changes leave the website alone', function () {
    ['owner' => $owner, 'property' => $property, 'site' => $site] = designSetup();
    $site->update(['site_template_id' => templateWithSlots()->id]);
    $beach = $property->addMedia(UploadedFile::fake()->image('beach.jpg'))->toMediaCollection(Property::PHOTOS);
    $pool = $property->addMedia(UploadedFile::fake()->image('pool.jpg'))->toMediaCollection(Property::PHOTOS);
    $copy = fn (int $photo) => $this->actingAs($owner)->post(route('settings.booking-website.design.images.copy', [$property, 'hero']), ['photo' => $photo]);

    $this->actingAs($owner)->get(route('settings.booking-website.design', $property))
        ->assertInertia(fn (Assert $page) => $page->has('design.albumPhotos', 2)->where('design.albumPhotos.0.id', $beach->id)->where('canManageAlbum', true));

    $copy($beach->id)->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Image saved.']);
    $copy($pool->id)->assertInertiaFlash('toast.type', 'success');

    $image = $site->refresh()->getFirstMedia(PropertyBookingSite::IMAGES);

    expect($site->getMedia(PropertyBookingSite::IMAGES))->toHaveCount(1)
        ->and($image->getCustomProperty('slot'))->toBe('hero')
        ->and($image->id)->not->toBe($pool->id)
        ->and($image->disk)->toBe('public')
        ->and(Activity::query()->where('event', 'booking-website.image-copied')->count())->toBe(2);

    Storage::disk('public')->assertExists($image->getPathRelativeToRoot());

    $pool->delete();

    expect($property->refresh()->getMedia(Property::PHOTOS))->toHaveCount(1)
        ->and($site->refresh()->getMedia(PropertyBookingSite::IMAGES))->toHaveCount(1);

    Storage::disk('public')->assertExists($image->getPathRelativeToRoot());
});

test('choosing from the album refuses a missing slot and another property\'s photo', function () {
    ['owner' => $owner, 'property' => $property, 'site' => $site] = designSetup();
    $site->update(['site_template_id' => templateWithSlots()->id]);
    $photo = $property->addMedia(UploadedFile::fake()->image('beach.jpg'))->toMediaCollection(Property::PHOTOS);
    $foreign = Property::factory()->create()->addMedia(UploadedFile::fake()->image('other.jpg'))->toMediaCollection(Property::PHOTOS);
    $logo = $property->addMedia(UploadedFile::fake()->image('logo.png'))->toMediaCollection(Property::LOGO);
    $url = fn (string $slot) => route('settings.booking-website.design.images.copy', [$property, $slot]);
    $refusal = ['type' => 'error', 'message' => 'The photo or image slot is no longer there. Reload the page and try again.'];

    $this->actingAs($owner)->post($url('missing'), ['photo' => $photo->id])->assertInertiaFlash('toast', $refusal);
    $this->actingAs($owner)->post($url('hero'), ['photo' => $foreign->id])->assertInertiaFlash('toast', $refusal);
    $this->actingAs($owner)->post($url('hero'), ['photo' => $logo->id])->assertInertiaFlash('toast', $refusal);
    $this->actingAs($owner)->post($url('hero'), [])->assertSessionHasErrors('photo');

    expect($site->refresh()->getMedia(PropertyBookingSite::IMAGES))->toHaveCount(0)
        ->and(Activity::query()->where('event', 'booking-website.image-copied')->exists())->toBeFalse();
});

test('choosing from the album needs the manage permission, and only album managers get the album link', function () {
    ['property' => $property, 'site' => $site] = designSetup();
    $site->update(['site_template_id' => templateWithSlots()->id]);
    $photo = $property->addMedia(UploadedFile::fake()->image('beach.jpg'))->toMediaCollection(Property::PHOTOS);
    $designer = User::factory()->for($property)->withPermissions([PermissionEnum::BookingWebsiteManage])->create();
    $other = User::factory()->for($property)->withPermissions([PermissionEnum::SettingsManage])->create();
    $url = route('settings.booking-website.design.images.copy', [$property, 'hero']);

    $this->actingAs($other)->post($url, ['photo' => $photo->id])->assertForbidden();

    $this->actingAs($designer)->get(route('settings.booking-website.design', $property))
        ->assertInertia(fn (Assert $page) => $page->has('design.albumPhotos', 1)->where('canManageAlbum', false));
    $this->actingAs($designer)->post($url, ['photo' => $photo->id])->assertInertiaFlash('toast.type', 'success');
});

test('the checklist asks for the required images of the chosen template', function () {
    ['site' => $site] = designSetup();
    $checklist = app(BookingSiteChecklist::class);
    $keys = fn () => collect($checklist->items($site->refresh()))->pluck('key')->all();

    expect($keys())->not->toContain('images');

    $site->update(['site_template_id' => templateWithSlots()->id]);
    $images = collect($checklist->items($site->refresh()))->firstWhere('key', 'images');

    expect($images->done)->toBeFalse()->and($images->detail)->toBe('Add: Hero image');

    $site->addMedia(UploadedFile::fake()->image('hero.jpg'))->withCustomProperties(['slot' => 'hero'])->toMediaCollection(PropertyBookingSite::IMAGES);
    $site->unsetRelation('media');

    expect(collect($checklist->items($site))->firstWhere('key', 'images')->done)->toBeTrue();
});

test('the seo tab saves the title and description and keeps them short', function () {
    ['owner' => $owner, 'property' => $property, 'site' => $site] = designSetup();

    $this->actingAs($owner)->get(route('settings.booking-website.seo', $property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/booking-website/seo')->where('seo.seoTitle', null));

    $this->actingAs($owner)->put(route('settings.booking-website.seo.update', $property), ['seo_title' => 'Sand Bay, Pangkor', 'seo_description' => 'Beachfront rooms'])
        ->assertRedirect(route('settings.booking-website.seo', $property))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'SEO saved.']);

    expect($site->refresh()->seo_title)->toBe('Sand Bay, Pangkor');
    expect(Activity::query()->where('event', 'booking-website.seo-saved')->count())->toBe(1);

    $this->actingAs($owner)->put(route('settings.booking-website.seo.update', $property), ['seo_title' => str_repeat('a', 71), 'seo_description' => str_repeat('a', 161)])
        ->assertSessionHasErrors(['seo_title', 'seo_description']);
});

test('design and seo need the manage permission, the feature, the right property and a microsite', function () {
    ['owner' => $owner, 'property' => $property, 'site' => $site] = designSetup();
    $staff = User::factory()->for($property)->withPermissions([PermissionEnum::SettingsManage])->create();

    foreach (['design', 'seo'] as $tab) {
        $this->actingAs($staff)->get(route('settings.booking-website.'.$tab, $property))->assertForbidden();
        $this->actingAs($owner)->get(route('settings.booking-website.'.$tab, Property::factory()->create()))->assertNotFound();
    }

    $site->update(['mode' => BookingEngineMode::ApiOnly]);

    foreach (['design', 'seo'] as $tab) {
        $this->actingAs($owner)->get(route('settings.booking-website.'.$tab, $property))->assertNotFound();
    }

    $site->update(['mode' => BookingEngineMode::Microsite]);
    Feature::for($property)->deactivate(BookingEngine::class);

    $this->actingAs($owner)->get(route('settings.booking-website.design', $property))->assertNotFound();
});

test('the main colour is offered and kept only when the saved template allows it', function () {
    ['owner' => $owner, 'property' => $property, 'site' => $site] = designSetup();
    $plain = templateWithSlots(['allows_main_color' => false]);
    $coloured = templateWithSlots();
    $save = fn (SiteTemplate $template) => $this->actingAs($owner)->put(route('settings.booking-website.design.update', $property), ['site_template_id' => $template->id, 'main_color' => '#0F766E']);

    $save($plain);
    expect($site->refresh()->main_color)->toBeNull();
    $this->actingAs($owner)->get(route('settings.booking-website.design', $property))
        ->assertInertia(fn (Assert $page) => $page->where('design.allowsMainColor', false));

    $save($coloured);
    expect($site->refresh()->main_color)->toBe('#0F766E');
    $this->actingAs($owner)->get(route('settings.booking-website.design', $property))
        ->assertInertia(fn (Assert $page) => $page->where('design.allowsMainColor', true));
});
