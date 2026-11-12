<?php

use App\Enums\DefaultRoleEnum;
use App\Enums\ReportKey;
use App\Models\Guest;
use App\Models\ReportLayout;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/ReportSetup.php';

/**
 * @param  list<string>  $hidden
 * @return array{orientation: string, columns: list<array{key: string, visible: bool}>}
 */
function guestLayoutPayload(array $order = [], array $hidden = []): array
{
    $keys = $order ?: array_map(fn ($column): string => $column->key, ReportKey::Guests->columns());

    return [
        'orientation' => 'portrait',
        'columns' => array_map(fn (string $key): array => ['key' => $key, 'visible' => ! in_array($key, $hidden, true)], $keys),
    ];
}

test('a manager saves the guest list layout and the list follows it', function () {
    [$user, $property] = reportSetup();
    $order = ['spend', 'full_name', 'email', 'phone', 'nationality', 'tags', 'last_visit', 'stays', 'created_at'];

    $this->actingAs($user)
        ->put("/p/{$property->code}/reports/guests/layout", guestLayoutPayload($order, ['tags']))
        ->assertRedirect();

    expect(ReportLayout::query()->where('property_id', $property->id)->sole()->report_key)->toBe(ReportKey::Guests);

    $this->get(route('guests.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canEditLayout', true)
            ->where('layout.columns.0.key', 'spend')
            ->where('layout.columns.1.key', 'full_name')
            ->where('layout.columns.5.visible', false));
});

test('a sort on a hidden column falls back to name', function () {
    [$user, $property] = reportSetup();
    Guest::factory()->create(['property_id' => $property->id]);

    $this->actingAs($user)->put("/p/{$property->code}/reports/guests/layout", guestLayoutPayload(hidden: ['email']));

    $this->get(route('guests.index', [$property->code, 'sort' => '-email']))
        ->assertInertia(fn (Assert $page) => $page->where('sort', 'full_name'));

    $this->get(route('guests.index', [$property->code, 'sort' => '-phone']))
        ->assertInertia(fn (Assert $page) => $page->where('sort', '-phone'));
});

test('the guest list layout has no exports or PDF preview', function () {
    [$user, $property] = reportSetup();

    $this->actingAs($user)->post("/p/{$property->code}/reports/guests/pdf")->assertNotFound();
    $this->post("/p/{$property->code}/reports/guests/spreadsheet")->assertNotFound();
    $this->post("/p/{$property->code}/reports/guests/layout/preview", guestLayoutPayload())->assertNotFound();
});

test('staff without settings.manage cannot edit the guest list layout', function () {
    [$user, $property] = reportSetup(DefaultRoleEnum::FrontDesk);

    $this->actingAs($user)->get(route('guests.index', $property->code))
        ->assertInertia(fn (Assert $page) => $page->where('canEditLayout', false));

    $this->put("/p/{$property->code}/reports/guests/layout", guestLayoutPayload())->assertForbidden();
});
