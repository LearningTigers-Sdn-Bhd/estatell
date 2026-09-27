<?php

use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('staff get a 404 on the admin property pages', function () {
    $staff = User::factory()->create();

    $this->actingAs($staff)->get(route('admin.properties.index'))->assertNotFound();
    $this->actingAs($staff)->get(route('admin.properties.show', $staff->property->code))->assertNotFound();
});

test('guests are sent to login from the admin property list', function () {
    $this->get(route('admin.properties.index'))->assertRedirect(route('login'));
});

test('admins see properties newest code first with staff counts', function () {
    $older = Property::factory()->create();
    $newer = Property::factory()->live()->create(['type' => PropertyType::Homestay]);
    User::factory()->count(2)->for($newer)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.properties.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/properties/index')
            ->has('properties.data', 2)
            ->where('properties.data.0.code', $newer->code)
            ->where('properties.data.0.status', ['value' => 'live', 'label' => 'Live', 'tone' => 'success'])
            ->where('properties.data.0.type', 'Homestay')
            ->where('properties.data.0.staffCount', 2)
            ->where('properties.data.1.code', $older->code)
            ->where('properties.meta.total', 2)
        );
});

test('the list paginates 25 per page', function () {
    Property::factory()->count(26)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.properties.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('properties.data', 1)
            ->where('properties.meta.current_page', 2)
            ->where('properties.meta.last_page', 2)
        );
});

test('filters by status and type', function () {
    Property::factory()->create(['type' => PropertyType::Hotel]);
    $match = Property::factory()->live()->create(['type' => PropertyType::Hostel]);
    Property::factory()->live()->create(['type' => PropertyType::Hotel]);

    $this->actingAs($this->admin)
        ->get(route('admin.properties.index', ['filter' => ['status' => PropertyStatus::Live->value, 'type' => PropertyType::Hostel->value]]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('properties.data', 1)
            ->where('properties.data.0.code', $match->code)
            ->where('filters.status', 'live')
            ->where('filters.type', 'hostel')
        );
});

test('a numeric search matches the code exactly', function () {
    $match = Property::factory()->create();
    Property::factory()->create(['name' => "Inn {$match->code}"]);

    $this->actingAs($this->admin)
        ->get(route('admin.properties.index', ['filter' => ['search' => (string) $match->code]]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('properties.data', 1)
            ->where('properties.data.0.code', $match->code)
        );
});

test('a text search matches name or city, ignoring case', function () {
    Property::factory()->create(['name' => 'Seaview Guesthouse', 'city' => 'Sandakan']);
    Property::factory()->create(['name' => 'Hill Lodge', 'city' => 'Kota Kinabalu']);
    Property::factory()->create(['name' => 'River Inn', 'city' => 'Kuching']);

    $this->actingAs($this->admin)
        ->get(route('admin.properties.index', ['filter' => ['search' => 'sea']]))
        ->assertInertia(fn (Assert $page) => $page->has('properties.data', 1)->where('properties.data.0.name', 'Seaview Guesthouse'));

    $this->actingAs($this->admin)
        ->get(route('admin.properties.index', ['filter' => ['search' => 'kinabalu']]))
        ->assertInertia(fn (Assert $page) => $page->has('properties.data', 1)->where('properties.data.0.name', 'Hill Lodge'));
});

test('sorts by name', function () {
    Property::factory()->create(['name' => 'Bravo']);
    Property::factory()->create(['name' => 'Alpha']);

    $this->actingAs($this->admin)
        ->get(route('admin.properties.index', ['sort' => 'name']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('properties.data.0.name', 'Alpha')
            ->where('sort', 'name')
        );
});

test('unknown sorts and filters are rejected', function (array $query) {
    $this->actingAs($this->admin)
        ->get(route('admin.properties.index', $query))
        ->assertStatus(400);
})->with([
    'sort' => [['sort' => 'tin']],
    'filter' => [['filter' => ['tin' => 'x']]],
]);

test('the list runs a fixed number of queries', function () {
    Model::preventLazyLoading();
    Property::factory()->count(5)->create()->each(fn (Property $property) => User::factory()->for($property)->create());

    $this->actingAs($this->admin);
    DB::enableQueryLog();

    $this->get(route('admin.properties.index'))->assertOk();

    $propertyQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], '"properties"'));
    expect($propertyQueries)->toHaveCount(2);
});

test('admins see a property with its staff', function () {
    $property = Property::factory()->create(['city' => 'Sandakan', 'contact_email' => 'hello@example.com']);
    User::factory()->for($property)->create(['name' => 'Bella']);
    User::factory()->for($property)->unverified()->create(['name' => 'Aina']);

    $this->actingAs($this->admin)
        ->get(route('admin.properties.show', $property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/properties/show')
            ->where('property.code', $property->code)
            ->where('property.city', 'Sandakan')
            ->where('property.contactEmail', 'hello@example.com')
            ->where('property.status.label', 'Onboarding')
            ->has('property.staff', 2)
            ->where('property.staff.0.name', 'Aina')
            ->where('property.staff.0.verified', false)
        );
});

test('an unknown property code is a 404 for admins', function () {
    $this->actingAs($this->admin)
        ->get('/admin/properties/99999999')
        ->assertNotFound();
});
