<?php

use App\Enums\PermissionEnum;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioManualBatch;
use App\Models\Reservation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/ManualFolioSetup.php';
require_once __DIR__.'/ManualLineActionsTest.php';

/**
 * @param  array<string, mixed>  $setup
 */
function mrUrl(array $setup, FolioLine $line, string $action, ?Folio $folio = null): string
{
    return route('reservations.folios.lines.'.$action, [
        'property' => $setup['property']->code,
        'reservation' => $setup['reservation']->id,
        'folio' => ($folio ?? $setup['folio'])->id,
        'line' => $line->id,
    ]);
}

test('the posted lines of the manual page offer the row menu and what its Dialogs need', function () {
    $setup = mfSetup();
    $line = mlCharge($setup, $setup['minibar']);
    $taxLine = $line->children()->firstOrFail();

    $this->actingAs($setup['user'])
        ->withSession(['manual_folio_reason' => 'Paper folio'])
        ->get(route('reservations.folios.manual', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('manual.lines.'.$line->id.'.total', '100.00')
            ->where('manual.lines.'.$line->id.'.taxes.0.lineId', $taxLine->id)
            ->where('manual.lines.'.$line->id.'.taxes.0.taxId', $setup['sst']->id)
            ->where('manual.posted.rows.0.actions', ['reverse', 'split', 'remove_tax', 'add_tax', 'recalculate_tax'])
            ->where('manual.posted.rows.1.actions', []));
});

test('a split posts at once, flashes a toast and keeps the reason of the Dialog', function () {
    $setup = mfSetup();
    $other = mlOther($setup);
    $line = mlCharge($setup, $setup['minibar']);

    $this->actingAs($setup['user'])
        ->post(mrUrl($setup, $line, 'split'), [
            'reason' => 'A charge was missed',
            'idempotency_key' => fake()->uuid(),
            'parts' => [
                ['folio_id' => $setup['folio']->id, 'amount' => '60.00'],
                ['folio_id' => $other->id, 'amount' => '40.00'],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(FolioManualBatch::query()->firstOrFail()->reason)->toBe('A charge was missed')
        ->and(FolioLine::query()->where('folio_id', $other->id)->where('amount', '40.00')->exists())->toBeTrue();
});

test('a refused action goes back with an error and writes nothing', function () {
    $setup = mfSetup();
    $line = mlCharge($setup, $setup['minibar']);
    $before = FolioLine::query()->count();

    $this->actingAs($setup['user'])
        ->post(mrUrl($setup, $line, 'split'), [
            'reason' => 'A charge was missed',
            'idempotency_key' => fake()->uuid(),
            'parts' => [
                ['folio_id' => $setup['folio']->id, 'amount' => '60.00'],
                ['folio_id' => $setup['folio']->id, 'amount' => '30.00'],
            ],
        ])
        ->assertSessionHasErrors('form');

    expect(FolioLine::query()->count())->toBe($before);
});

test('tax routes remove, add and recalculate a tax line', function () {
    $setup = mfSetup();
    $line = mlCharge($setup, $setup['minibar']);
    $taxLine = $line->children()->firstOrFail();
    $send = fn (string $action, array $data) => $this->actingAs($setup['user'])->post(mrUrl($setup, $line, $action), ['reason' => 'Fixing a mistake', 'idempotency_key' => fake()->uuid(), ...$data]);

    $send('tax.remove', ['tax_ids' => [$taxLine->id]])->assertSessionHasNoErrors();
    $send('tax.remove', ['tax_ids' => [$taxLine->id]])->assertSessionHasErrors('form');
    $send('tax.add', ['tax_ids' => [$setup['sst']->id]])->assertSessionHasNoErrors();
    $send('tax.recalculate', [])->assertSessionHasErrors('form');
    $send('tax.add', [])->assertSessionHasErrors('tax_ids');

    expect(FolioManualBatch::query()->count())->toBe(2);
});

test('the row menu routes need folio.manual and stay on the reservation', function () {
    $setup = mfSetup();
    $line = mlCharge($setup, $setup['minibar']);
    $plain = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::ReservationsView, PermissionEnum::FolioReverse])->create();
    $payload = ['reason' => 'Fixing a mistake', 'idempotency_key' => fake()->uuid()];

    $this->actingAs($plain)->post(mrUrl($setup, $line, 'tax.recalculate'), $payload)->assertForbidden();

    $strangerFolio = Folio::factory()->master()->create(['property_id' => $setup['property']->id, 'reservation_id' => Reservation::factory()->create(['property_id' => $setup['property']->id])->id]);

    $this->actingAs($setup['user'])->post(mrUrl($setup, $line, 'tax.recalculate', $strangerFolio), $payload)->assertNotFound();

    $this->actingAs($setup['user'])->post(mrUrl($setup, $line, 'split'), [
        ...$payload,
        'parts' => [
            ['folio_id' => $setup['folio']->id, 'amount' => '60.00'],
            ['folio_id' => $strangerFolio->id, 'amount' => '40.00'],
        ],
    ])->assertNotFound();

    expect(FolioManualBatch::query()->count())->toBe(0);
});

test('the folio tab offers the manual items next to its own for folio.manual only', function () {
    $setup = mfSetup();
    $line = mlCharge($setup, $setup['minibar']);
    $plain = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::ReservationsView, PermissionEnum::FolioReverse])->create();
    $url = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['folio']->id]);

    $this->actingAs($setup['user'])->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('manualLines.'.$line->id.'.total', '100.00')
        ->where('folio.rows.0.actions', fn ($actions) => collect($actions)->contains('reverse') && collect($actions)->contains('split') && collect($actions)->contains('recalculate_tax'))
        ->missing('manualFolios')
        ->missing('manualTaxes'));

    $this->actingAs($setup['user'])
        ->get($url, ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'reservations/show', 'X-Inertia-Partial-Data' => 'manualFolios,manualTaxes', 'X-Inertia-Version' => Inertia\Inertia::getVersion()])
        ->assertOk()
        ->assertJsonCount(1, 'props.manualFolios')
        ->assertJsonCount(1, 'props.manualTaxes');

    $this->actingAs($plain)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('manualLines', [])
        ->where('folio.rows.0.actions', fn ($actions) => collect($actions)->contains('reverse') && ! collect($actions)->contains('split')));
});
