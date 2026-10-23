<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Models\Partner;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('staff get a 404 on the admin property pages', function (string $route) {
    $staff = User::factory()->create();

    $this->actingAs($staff)->get(route($route, $staff->property->code))->assertNotFound();
})->with(['admin.properties.show', 'admin.properties.staff', 'admin.properties.features']);

test('staff get a 404 on the admin property list', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.properties.index'))->assertNotFound();
});

test('guests are sent to login from the admin property list', function () {
    $this->get(route('admin.properties.index'))->assertRedirect(route('login'));
});

test('admins see properties newest first with their owner', function () {
    $older = Property::factory()->onboarding()->create(['created_at' => now()->subDay()]);
    $newer = Property::factory()->live()->create(['type' => PropertyType::Homestay, 'created_at' => now()]);
    User::factory()->for($newer)->owner()->deactivated()->create(['name' => 'Former owner']);
    User::factory()->for($newer)->withRole(DefaultRoleEnum::Owner)->create(['name' => 'Aina', 'email' => 'aina@example.com']);
    User::factory()->for($newer)->withRole(DefaultRoleEnum::FrontDesk)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.properties.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/properties/index')
            ->has('properties.data', 2)
            ->where('properties.data.0.code', $newer->code)
            ->where('properties.data.0.status', ['value' => 'live', 'label' => 'Live', 'tone' => 'success'])
            ->where('properties.data.0.type', 'Homestay')
            ->where('properties.data.0.ownerName', 'Aina')
            ->where('properties.data.0.ownerEmail', 'aina@example.com')
            ->where('properties.data.1.code', $older->code)
            ->where('properties.data.1.ownerName', null)
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

test('a property code search matches the code exactly, ignoring case', function () {
    $match = Property::factory()->create();
    Property::factory()->create(['name' => "Inn {$match->code}"]);

    $this->actingAs($this->admin)
        ->get(route('admin.properties.index', ['filter' => ['search' => strtolower($match->code)]]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('properties.data', 1)
            ->where('properties.data.0.code', $match->code)
        );
});

test('the default sort is newest created first', function () {
    $oldest = Property::factory()->create(['created_at' => now()->subDays(2)]);
    $newest = Property::factory()->create(['created_at' => now()]);
    $middle = Property::factory()->create(['created_at' => now()->subDay()]);

    $this->actingAs($this->admin)
        ->get(route('admin.properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sort', '-created_at')
            ->where('properties.data.0.code', $newest->code)
            ->where('properties.data.1.code', $middle->code)
            ->where('properties.data.2.code', $oldest->code)
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
    Property::factory()->count(5)->create()->each(fn (Property $property) => User::factory()->for($property)->create());

    $this->actingAs($this->admin);
    Model::preventLazyLoading();
    DB::enableQueryLog();

    try {
        $this->get(route('admin.properties.index'))->assertOk();
    } finally {
        Model::preventLazyLoading(false);
    }

    $propertyQueries = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], '"properties"'));
    expect($propertyQueries)->toHaveCount(3);
});

test('the overview tab sends the property without tab data', function () {
    $property = Property::factory()->onboarding()->create(['city' => 'Sandakan', 'contact_email' => 'hello@example.com']);
    User::factory()->for($property)->owner()->create(['name' => 'Aina']);
    User::factory()->for($property)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.properties.show', $property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/properties/show')
            ->where('tab', 'overview')
            ->where('property.code', $property->code)
            ->where('property.city', 'Sandakan')
            ->where('property.contactEmail', 'hello@example.com')
            ->where('property.status.label', 'Onboarding')
            ->where('property.staffCount', 2)
            ->where('property.ownerName', 'Aina')
            ->missing('staff')
            ->missing('features')
        );
});

test('the staff tab has its own URL and lists staff by name', function () {
    $property = Property::factory()->create();
    User::factory()->for($property)->owner()->deactivated()->create(['name' => 'Bella']);
    User::factory()->for($property)->unverified()->create(['name' => 'Aina']);

    $this->actingAs($this->admin)
        ->get(route('admin.properties.staff', $property->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/properties/show')
            ->where('tab', 'staff')
            ->has('staff', 2)
            ->where('staff.0.name', 'Aina')
            ->where('staff.0.verified', false)
            ->where('staff.0.role', null)
            ->where('staff.0.canSendSetupLink', false)
            ->where('staff.1.role', 'Owner')
            ->where('staff.1.deactivated', true)
            ->where('staff.1.canSendSetupLink', false)
            ->missing('features')
        );
});

test('the staff tab offers setup email only for an unverified owner', function () {
    $property = Property::factory()->create();
    User::factory()->for($property)->unverified()->owner()->create(['name' => 'Aina']);
    User::factory()->for($property)->unverified()->create(['name' => 'Bella']);

    $this->actingAs($this->admin)
        ->get(route('admin.properties.staff', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('staff.0.name', 'Aina')
            ->where('staff.0.canSendSetupLink', true)
            ->where('staff.1.name', 'Bella')
            ->where('staff.1.canSendSetupLink', false));
});

test('an unknown property code is a 404 for admins', function () {
    $this->actingAs($this->admin)
        ->get('/admin/properties/99999999')
        ->assertNotFound();
});

test('the list shows each property\'s partner and filters by partner or Direct', function () {
    $partner = Partner::factory()->create(['name' => 'Andaman Stays']);
    $other = Partner::factory()->create(['name' => 'Zanzibar Stays']);
    $brought = Property::factory()->create(['partner_id' => $partner->id, 'created_at' => now()->subDays(3)]);
    $otherBrought = Property::factory()->create(['partner_id' => $other->id, 'created_at' => now()->subDays(2)]);
    $direct = Property::factory()->create(['created_at' => now()->subDay()]);

    $this->actingAs($this->admin)
        ->get(route('admin.properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('properties.data', 3)
            ->where('properties.data.2.partnerName', 'Andaman Stays')
            ->where('properties.data.0.partnerName', null)
            ->where('properties.data.0.code', $direct->code)
            ->where('partners.0', ['value' => 'direct', 'label' => 'Direct'])
            ->where('partners.1.value', (string) $partner->id)
        );

    $this->get(route('admin.properties.index', ['filter' => ['partner' => (string) $partner->id]]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('properties.data', 1)
            ->where('properties.data.0.code', $brought->code)
            ->where('filters.partner', (string) $partner->id)
        );

    $this->get(route('admin.properties.index', ['filter' => ['partner' => 'direct']]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('properties.data', 1)
            ->where('properties.data.0.code', $direct->code)
        );

    $this->get(route('admin.properties.index', ['filter' => ['partner' => 'nonsense']]))
        ->assertInertia(fn (Assert $page) => $page->has('properties.data', 0));

    expect($otherBrought->partner_id)->toBe($other->id);
});
