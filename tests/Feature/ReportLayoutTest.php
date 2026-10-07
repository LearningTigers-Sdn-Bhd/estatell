<?php

use App\Actions\Reports\ResetReportLayout;
use App\Actions\Reports\UpdateReportLayout;
use App\Data\Reports\ReportFilters;
use App\Enums\DefaultRoleEnum;
use App\Enums\PageOrientation;
use App\Enums\PermissionEnum;
use App\Enums\ReportKey;
use App\Models\BusinessDate;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\ReportLayout;
use App\Models\ReportLayoutColumn;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\Property\PrintProfile;
use App\Services\Reports\ReportLayoutResolver;
use App\Services\Reports\ReportPdf;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

/**
 * @return array<string, mixed>
 */
function layoutPayload(?array $keys = null, array $hidden = ['phone', 'guests', 'company'], string $orientation = 'landscape'): array
{
    $keys ??= array_map(fn ($column): string => $column->key, ReportKey::Arrivals->columns());

    return [
        'orientation' => $orientation,
        'columns' => array_map(fn (string $key): array => ['key' => $key, 'visible' => ! in_array($key, $hidden, true)], $keys),
    ];
}

/**
 * @return list<array{key: string, visible: bool}>
 */
function layoutDraft(?array $keys = null, array $hidden = []): array
{
    return layoutPayload($keys, $hidden)['columns'];
}

function layoutUrl(Property $property, string $suffix = ''): string
{
    return "/p/{$property->code}/reports/arrivals/layout".$suffix;
}

/**
 * @return array{0: User, 1: Property}
 */
function layoutSetup(DefaultRoleEnum $role = DefaultRoleEnum::Manager): array
{
    $user = User::factory()->withRole($role)->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-10']);

    return [$user, $property];
}

function storeLayout(Property $property, array $columns, PageOrientation $orientation = PageOrientation::Portrait): ReportLayout
{
    $layout = ReportLayout::factory()->create(['property_id' => $property->id, 'orientation' => $orientation]);

    foreach ($columns as $position => [$key, $visible]) {
        ReportLayoutColumn::factory()->create(['report_layout_id' => $layout->id, 'column_key' => $key, 'position' => $position, 'is_visible' => $visible]);
    }

    return $layout;
}

test('with no stored layout the resolver returns the code defaults', function () {
    [, $property] = layoutSetup();

    $layout = app(ReportLayoutResolver::class)->resolve($property, ReportKey::Arrivals);

    expect($layout->orientation)->toBe(PageOrientation::Portrait)
        ->and(array_column($layout->columns, 'key'))->toBe(array_map(fn ($column): string => $column->key, ReportKey::Arrivals->columns()))
        ->and($layout->visibleKeys())->toBe(['reservation', 'guest', 'arrival', 'nights', 'unit', 'status', 'balance']);
});

test('stored columns keep their order, a new code column goes last and a stale key is ignored', function () {
    [, $property] = layoutSetup();
    storeLayout($property, [
        ['balance', true], ['stale_key', true], ['guest', false], ['reservation', true], ['phone', true],
    ], PageOrientation::Landscape);

    $layout = app(ReportLayoutResolver::class)->resolve($property, ReportKey::Arrivals);
    $keys = array_column($layout->columns, 'key');

    expect($layout->orientation)->toBe(PageOrientation::Landscape)
        ->and(array_slice($keys, 0, 4))->toBe(['balance', 'guest', 'reservation', 'phone'])
        ->and($keys)->toHaveCount(10)->not->toContain('stale_key')
        ->and(array_slice($keys, 4))->toBe(['arrival', 'nights', 'unit', 'guests', 'company', 'status']);

    $visible = array_column($layout->columns, 'visible', 'key');

    expect($visible['balance'])->toBeTrue()
        ->and($visible['guest'])->toBeFalse()
        ->and($visible['phone'])->toBeTrue()
        ->and($visible['arrival'])->toBeTrue()
        ->and($visible['guests'])->toBeFalse();
});

test('the resolver never reads the layout of another property', function () {
    [, $property] = layoutSetup();
    [, $other] = layoutSetup();
    storeLayout($other, [['balance', true]], PageOrientation::Landscape);

    expect(app(ReportLayoutResolver::class)->resolve($property, ReportKey::Arrivals)->orientation)->toBe(PageOrientation::Portrait);
});

test('UpdateReportLayout saves the orientation and the columns in order and logs it', function () {
    [$user, $property] = layoutSetup();
    $keys = array_reverse(array_map(fn ($column): string => $column->key, ReportKey::Arrivals->columns()));

    app(UpdateReportLayout::class)($user, $property, ReportKey::Arrivals, PageOrientation::Landscape, layoutDraft($keys, ['phone']));

    $layout = ReportLayout::query()->where('property_id', $property->id)->sole();

    expect($layout->orientation)->toBe(PageOrientation::Landscape)
        ->and($layout->columns->pluck('column_key')->all())->toBe($keys)
        ->and($layout->columns->pluck('position')->all())->toBe(range(0, 9))
        ->and($layout->columns->firstWhere('column_key', 'phone')->is_visible)->toBeFalse();

    $activity = Activity::query()->where('event', 'report_layout.updated')->sole();

    expect($activity->causer_id)->toBe($user->id)
        ->and($activity->properties['property_id'])->toBe($property->id)
        ->and($activity->properties['report_key'])->toBe('arrivals');
});

test('saving again replaces the columns and keeps one layout', function () {
    [$user, $property] = layoutSetup();
    $action = app(UpdateReportLayout::class);

    $action($user, $property, ReportKey::Arrivals, PageOrientation::Landscape, layoutDraft());
    $action($user, $property, ReportKey::Arrivals, PageOrientation::Portrait, layoutDraft(hidden: ['guest']));

    expect(ReportLayout::query()->count())->toBe(1)
        ->and(ReportLayoutColumn::query()->count())->toBe(10)
        ->and(ReportLayout::query()->sole()->orientation)->toBe(PageOrientation::Portrait);
});

test('UpdateReportLayout refuses missing, extra and duplicate keys and an all-hidden list', function (array $draft) {
    [$user, $property] = layoutSetup();

    expect(fn () => app(UpdateReportLayout::class)($user, $property, ReportKey::Arrivals, PageOrientation::Portrait, $draft))
        ->toThrow(ValidationException::class);

    expect(ReportLayout::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);
})->with([
    'missing' => fn () => array_slice(layoutDraft(), 1),
    'extra' => fn () => [...layoutDraft(), ['key' => 'bogus', 'visible' => true]],
    'duplicate' => fn () => [...array_slice(layoutDraft(), 1), ['key' => 'guest', 'visible' => true]],
    'all hidden' => fn () => array_map(fn (array $column): array => [...$column, 'visible' => false], layoutDraft()),
]);

test('ResetReportLayout deletes the layout, logs it and leaves other properties alone', function () {
    [$user, $property] = layoutSetup();
    [, $other] = layoutSetup();
    storeLayout($property, [['guest', true]]);
    storeLayout($other, [['guest', true]]);

    app(ResetReportLayout::class)($user, $property, ReportKey::Arrivals);

    expect(ReportLayout::query()->pluck('property_id')->all())->toBe([$other->id])
        ->and(ReportLayoutColumn::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'report_layout.updated')->count())->toBe(1);

    app(ResetReportLayout::class)($user, $property, ReportKey::Arrivals);

    expect(Activity::query()->count())->toBe(1);
});

test('a manager saves and resets the layout through the routes', function () {
    [$user, $property] = layoutSetup();

    $this->actingAs($user)->put(layoutUrl($property), layoutPayload())->assertRedirect();

    expect(ReportLayout::query()->where('property_id', $property->id)->sole()->orientation)->toBe(PageOrientation::Landscape);

    $this->actingAs($user)->delete(layoutUrl($property))->assertRedirect();

    expect(ReportLayout::query()->count())->toBe(0);
});

test('a bad layout is rejected with an error on columns', function () {
    [$user, $property] = layoutSetup();

    $this->actingAs($user)->put(layoutUrl($property), layoutPayload(['guest']))->assertSessionHasErrors('columns');
    $this->actingAs($user)->put(layoutUrl($property), [...layoutPayload(), 'orientation' => 'sideways'])->assertSessionHasErrors('orientation');
    $this->actingAs($user)->put(layoutUrl($property), ['orientation' => 'portrait'])->assertSessionHasErrors('columns');
});

test('the layout routes need reports.view and settings.manage', function () {
    [$front, $property] = layoutSetup(DefaultRoleEnum::FrontDesk);
    [$finance, $financeProperty] = layoutSetup(DefaultRoleEnum::Finance);

    expect(DefaultRoleEnum::Finance->permissions())->not->toContain(PermissionEnum::SettingsManage);

    foreach ([[$front, $property], [$finance, $financeProperty]] as [$user, $own]) {
        $this->actingAs($user)->put(layoutUrl($own), layoutPayload())->assertForbidden();
        $this->actingAs($user)->delete(layoutUrl($own))->assertForbidden();
        $this->actingAs($user)->post(layoutUrl($own, '/preview'), layoutPayload())->assertForbidden();
    }

    expect(ReportLayout::query()->count())->toBe(0);
});

test('another property returns 404 and its layout is never written', function () {
    [$manager] = layoutSetup();
    [, $other] = layoutSetup();

    $this->actingAs($manager)->put(layoutUrl($other), layoutPayload())->assertNotFound();
    $this->actingAs($manager)->delete(layoutUrl($other))->assertNotFound();

    expect(ReportLayout::query()->count())->toBe(0);
});

test('an unknown report slug returns 404', function () {
    [$user, $property] = layoutSetup();

    $this->actingAs($user)->put("/p/{$property->code}/reports/bogus/layout", layoutPayload())->assertNotFound();
});

test('the arrivals page receives the saved layout and canEditLayout', function () {
    [$manager, $property] = layoutSetup();
    storeLayout($property, [['balance', true], ['guest', true], ['reservation', false]], PageOrientation::Landscape);

    $this->actingAs($manager)->get("/p/{$property->code}/reports/arrivals")->assertInertia(fn (Assert $page) => $page
        ->where('canEditLayout', true)
        ->where('layout.orientation', 'landscape')
        ->has('layout.columns', 10)
        ->where('layout.columns.0', ['key' => 'balance', 'label' => 'Balance', 'align' => 'right', 'visible' => true])
        ->where('layout.columns.2.visible', false)
        ->missing('columns'));

    [$finance, $financeProperty] = layoutSetup(DefaultRoleEnum::Finance);

    $this->actingAs($finance)->get("/p/{$financeProperty->code}/reports/arrivals")->assertInertia(fn (Assert $page) => $page
        ->where('canEditLayout', false)
        ->where('layout.orientation', 'portrait'));
});

test('the sort falls back to the default when the sorted column is hidden', function () {
    [$manager, $property] = layoutSetup();
    storeLayout($property, [['guest', false], ['reservation', true], ['arrival', true]]);

    $this->actingAs($manager)->get("/p/{$property->code}/reports/arrivals?sort=-guest")->assertInertia(fn (Assert $page) => $page
        ->where('sort', 'arrival'));

    $this->actingAs($manager)->get("/p/{$property->code}/reports/arrivals?sort=-reservation")->assertInertia(fn (Assert $page) => $page
        ->where('sort', '-reservation'));
});

test('the preview returns a PDF of the draft layout without saving it', function () {
    [$user, $property] = layoutSetup();

    $response = $this->actingAs($user)->post(layoutUrl($property, '/preview'), [...layoutPayload(), 'from' => '2026-10-10', 'to' => '2026-10-11']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline')
        ->and(ReportLayout::query()->count())->toBe(0);
});

test('the preview checks the dates and the draft columns', function () {
    [$user, $property] = layoutSetup();

    $this->actingAs($user)->post(layoutUrl($property, '/preview'), [...layoutPayload(), 'from' => '2026-10-12', 'to' => '2026-10-10'])->assertSessionHasErrors('to');
    $this->actingAs($user)->post(layoutUrl($property, '/preview'), [...layoutPayload(['guest']), 'from' => '2026-10-10', 'to' => '2026-10-10'])->assertSessionHasErrors('columns');
});

test('the report view prints only the visible columns in layout order', function () {
    [, $property] = layoutSetup();
    $booking = Reservation::factory()->create(['property_id' => $property->id]);
    ReservationUnit::factory()->for($booking)->create(['arrival_date' => '2026-10-10', 'departure_date' => '2026-10-12']);
    $layout = app(ReportLayoutResolver::class)->fromDraft(ReportKey::Arrivals, PageOrientation::Landscape, layoutDraft(['balance', 'reservation', ...array_diff(array_map(fn ($column): string => $column->key, ReportKey::Arrivals->columns()), ['balance', 'reservation'])], ['guest', 'phone', 'arrival', 'nights', 'unit', 'guests', 'company', 'status']));
    $filters = new ReportFilters('2026-10-10', '2026-10-10');
    $rows = ReportKey::Arrivals->report()->rows($property, $filters);

    $html = view('pdf.report', [
        'print' => app(PrintProfile::class)->for($property),
        'title' => 'Arrivals',
        'dates' => '10 Oct 2026',
        'currency' => 'MYR',
        'columns' => $layout->visibleColumns(),
        'rows' => $rows,
        'selectedOnly' => false,
    ])->render();

    expect($html)->toContain($booking->reference)
        ->and(strpos($html, 'Balance'))->toBeLessThan(strpos($html, 'Reservation no.'))
        ->and($html)->not->toContain('<th>Guest</th>')
        ->and($html)->toContain('table-header-group');

    $pdf = app(ReportPdf::class)->make($property, ReportKey::Arrivals, $filters, $layout);

    expect($pdf->output())->toStartWith('%PDF');
});
