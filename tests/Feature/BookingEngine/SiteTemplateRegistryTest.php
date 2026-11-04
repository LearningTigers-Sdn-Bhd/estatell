<?php

use App\Enums\SiteTemplateStatus;
use App\Enums\SiteTemplateType;
use App\Models\Property;
use App\Models\SiteTemplate;
use App\Models\SiteTemplateSlot;
use App\Models\User;
use Database\Seeders\SiteTemplateSeeder;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('the seeder adds two draft templates once', function () {
    $this->seed(SiteTemplateSeeder::class);
    SiteTemplate::query()->where('key', 'city-hotel')->firstOrFail()->update(['status' => SiteTemplateStatus::Available]);
    $this->seed(SiteTemplateSeeder::class);

    expect(SiteTemplate::query()->count())->toBe(2)
        ->and(SiteTemplate::query()->where('key', 'island-resort')->firstOrFail()->slots)->toHaveCount(3)
        ->and(SiteTemplate::query()->where('key', 'city-hotel')->firstOrFail()->status)->toBe(SiteTemplateStatus::Available);
});

test('the database refuses a custom template without a property and a standard one with a property', function () {
    expect(fn () => SiteTemplate::factory()->create(['type' => SiteTemplateType::Custom, 'property_id' => null]))->toThrow(QueryException::class);
    expect(fn () => SiteTemplate::factory()->create(['type' => SiteTemplateType::Standard, 'property_id' => Property::factory()->create()->id]))->toThrow(QueryException::class);
});

test('admins see every template with its slots and the sites using it', function () {
    $template = SiteTemplate::factory()->create(['name' => 'Island resort']);
    SiteTemplateSlot::factory()->required()->create(['site_template_id' => $template->id, 'key' => 'hero', 'label' => 'Hero image']);
    SiteTemplate::factory()->draft()->custom()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.site-templates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/site-templates/index')
            ->has('templates', 2)
            ->where('templates.0.name', 'Island resort')
            ->where('templates.0.slots.0.key', 'hero')
            ->where('templates.0.slots.0.required', true)
            ->where('templates.0.transitions.0.value', 'draft')
            ->where('templates.0.sitesCount', 0)
            ->where('templates.1.type', 'custom'));
});

test('property staff cannot open or change the registry', function () {
    $owner = User::factory()->owner()->create();
    $template = SiteTemplate::factory()->create();

    $this->actingAs($owner)->get(route('admin.site-templates.index'))->assertNotFound();
    $this->actingAs($owner)->put(route('admin.site-templates.status.update', $template), ['status' => 'retired'])->assertNotFound();

    expect($template->refresh()->status)->toBe(SiteTemplateStatus::Available);
});

test('writes need two factor', function () {
    config(['estatell.support_require_two_factor' => true]);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.site-templates.store'), ['key' => 'beach', 'name' => 'Beach', 'type' => 'standard'])
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);

    expect(SiteTemplate::query()->count())->toBe(0);
});

test('an admin adds a standard template as a draft with its slots and the add is logged', function () {
    $this->actingAs(User::factory()->admin()->withTwoFactor()->create())
        ->post(route('admin.site-templates.store'), [
            'key' => 'beach-club',
            'name' => ' Beach club ',
            'type' => 'standard',
            'preview_url' => 'https://booking.estatell.com/demo-beach-club',
            'allows_main_color' => true,
            'slots' => [
                ['key' => 'hero', 'label' => 'Hero image', 'required' => true],
                ['key' => 'logo', 'label' => 'Logo', 'required' => false],
            ],
        ])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Beach club added as a draft.']);

    $template = SiteTemplate::query()->where('key', 'beach-club')->firstOrFail();

    expect($template->status)->toBe(SiteTemplateStatus::Draft)
        ->and($template->allows_main_color)->toBeTrue()
        ->and($template->type)->toBe(SiteTemplateType::Standard)
        ->and($template->property_id)->toBeNull()
        ->and($template->slots->pluck('key')->all())->toBe(['hero', 'logo'])
        ->and($template->slots->first()->required)->toBeTrue();
    expect(Activity::query()->where('event', 'site-template.created')->count())->toBe(1);
});

test('a custom template needs a property and a standard one refuses one', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $property = Property::factory()->create();

    $this->actingAs($admin)->post(route('admin.site-templates.store'), ['key' => 'pearl', 'name' => 'Pearl', 'type' => 'custom'])
        ->assertSessionHasErrors('property_id');
    $this->actingAs($admin)->post(route('admin.site-templates.store'), ['key' => 'pearl', 'name' => 'Pearl', 'type' => 'standard', 'property_id' => $property->id])
        ->assertSessionHasErrors('property_id');
    $this->actingAs($admin)->post(route('admin.site-templates.store'), ['key' => 'pearl', 'name' => 'Pearl', 'type' => 'custom', 'property_id' => $property->id])
        ->assertSessionHasNoErrors();

    expect(SiteTemplate::query()->where('key', 'pearl')->firstOrFail()->property_id)->toBe($property->id);
});

test('the key is unique, lower case and slot keys cannot repeat', function () {
    SiteTemplate::factory()->create(['key' => 'beach-club']);
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)->post(route('admin.site-templates.store'), ['key' => 'beach-club', 'name' => 'Beach', 'type' => 'standard'])
        ->assertSessionHasErrors('key');
    $this->actingAs($admin)->post(route('admin.site-templates.store'), ['key' => 'Beach Club', 'name' => 'Beach', 'type' => 'standard'])
        ->assertSessionHasErrors('key');
    $this->actingAs($admin)->post(route('admin.site-templates.store'), [
        'key' => 'new-one', 'name' => 'New', 'type' => 'standard',
        'slots' => [['key' => 'hero', 'label' => 'A'], ['key' => 'hero', 'label' => 'B']],
    ])->assertSessionHasErrors('slots.0.key');

    expect(SiteTemplate::query()->count())->toBe(1);
});

test('an update keeps the key, type and property, syncs the slots and logs the change', function () {
    $property = Property::factory()->create();
    $template = SiteTemplate::factory()->custom($property)->create(['key' => 'pearl', 'name' => 'Pearl']);
    SiteTemplateSlot::factory()->create(['site_template_id' => $template->id, 'key' => 'hero', 'label' => 'Hero']);
    SiteTemplateSlot::factory()->create(['site_template_id' => $template->id, 'key' => 'old', 'label' => 'Old']);
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($admin)
        ->put(route('admin.site-templates.update', $template), [
            'key' => 'hijack', 'type' => 'standard', 'property_id' => null,
            'name' => 'Pearl Bay',
            'slots' => [['key' => 'logo', 'label' => 'Logo', 'required' => false], ['key' => 'hero', 'label' => 'Hero image', 'required' => true]],
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Template saved.']);

    $template->refresh()->load('slots');

    expect($template->key)->toBe('pearl')
        ->and($template->type)->toBe(SiteTemplateType::Custom)
        ->and($template->property_id)->toBe($property->id)
        ->and($template->name)->toBe('Pearl Bay')
        ->and($template->slots->pluck('key')->all())->toBe(['logo', 'hero'])
        ->and($template->slots->last()->label)->toBe('Hero image');
    expect(Activity::query()->where('event', 'site-template.updated')->count())->toBe(1);

    $this->actingAs($admin)->put(route('admin.site-templates.update', $template), [
        'name' => 'Pearl Bay',
        'slots' => [['key' => 'logo', 'label' => 'Logo', 'required' => false], ['key' => 'hero', 'label' => 'Hero image', 'required' => true]],
    ]);

    expect(Activity::query()->where('event', 'site-template.updated')->count())->toBe(1);
});

test('status moves follow the lifecycle and a refused move writes nothing', function () {
    $template = SiteTemplate::factory()->draft()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $move = fn (string $status) => $this->actingAs($admin)->put(route('admin.site-templates.status.update', $template), ['status' => $status]);

    $move('retired')->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'That change did not apply. Reload the page and try again.']);
    expect($template->refresh()->status)->toBe(SiteTemplateStatus::Draft)
        ->and(Activity::query()->where('event', 'site-template.status-changed')->count())->toBe(0);

    $move('available')->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Template saved.']);
    $move('retired');

    expect($template->refresh()->status)->toBe(SiteTemplateStatus::Retired)
        ->and(Activity::query()->where('event', 'site-template.status-changed')->count())->toBe(2);

    $move('bogus')->assertSessionHasErrors('status');
});
