<?php

use App\Actions\Folio\PostingResult;
use App\Actions\Folio\RemoveUnitTaxWaiver;
use App\Actions\Folio\TaxWaiverResult;
use App\Actions\Folio\WaiveUnitTax;
use App\Enums\DefaultRoleEnum;
use App\Enums\FolioStatus;
use App\Enums\PermissionEnum;
use App\Enums\TaxWaiverReason;
use App\Enums\UnitStatus;
use App\Models\BookingSource;
use App\Models\Folio;
use App\Models\FolioManualBatch;
use App\Models\ReservationUnitTaxWaiver;
use App\Models\Tax;
use App\Models\User;
use App\Services\Folio\ScheduledFolioRows;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/TaxWaiverSetup.php';

function wtWaive(array $setup, ?array $taxIds = null): TaxWaiverResult
{
    return app(WaiveUnitTax::class)($setup['user'], $setup['unit'], $taxIds ?? [$setup['tourism']->id], TaxWaiverReason::PaidElsewhere, 'Trip.com', '1128152109837544');
}

function wtUrl(array $setup, string $path = 'tax-waivers'): string
{
    return "/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/units/{$setup['unit']->id}/{$path}";
}

test('waiving a tax reverses it on posted nights and the audit stops charging it', function () {
    $setup = wtSetup();
    wtPostNight($setup, '2026-10-12');

    $result = wtWaive($setup);

    $waiver = ReservationUnitTaxWaiver::query()->sole();
    expect($result->isRefused())->toBeFalse()
        ->and($result->reversed)->toBe(1)
        ->and($waiver->only(['reservation_unit_id', 'tax_id', 'paid_to', 'reference', 'user_id']))->toBe([
            'reservation_unit_id' => $setup['unit']->id,
            'tax_id' => $setup['tourism']->id,
            'paid_to' => 'Trip.com',
            'reference' => '1128152109837544',
            'user_id' => $setup['user']->id,
        ])
        ->and(wtLiveTaxes())->toBe(['Service Tax' => '8.00'])
        ->and(FolioManualBatch::query()->sole()->reason)->toBe('Tax paid by Trip.com · 1128152109837544')
        ->and(Activity::query()->where('event', 'reservation.unit_tax_waived')->sole()->properties['tax_ids'])->toBe([$setup['tourism']->id]);

    wtPostNight($setup, '2026-10-13');

    expect(wtLiveTaxes())->toBe(['Service Tax' => '16.00']);
});

test('scheduled rows and the collect now total leave out a waived tax', function () {
    $setup = wtSetup();
    wtWaive($setup);

    $blocks = app(ScheduledFolioRows::class)->forReservation($setup['property'], $setup['reservation'], []);
    $descriptions = collect($blocks)->flatMap(fn (array $block): array => array_map(fn ($row): string => $row->description, $block['rows']))->unique()->values()->all();

    expect($descriptions)->toContain('Service Tax')->not->toContain('Tourism tax');
});

test('a guest receipt waiver keeps no payer', function () {
    $setup = wtSetup();

    app(WaiveUnitTax::class)($setup['user'], $setup['unit'], [$setup['tourism']->id], TaxWaiverReason::GuestReceipt, 'Ignored', 'TTX-88231');

    expect(ReservationUnitTaxWaiver::query()->sole()->only(['reason', 'paid_to', 'reference']))
        ->toBe(['reason' => TaxWaiverReason::GuestReceipt, 'paid_to' => null, 'reference' => 'TTX-88231']);
});

test('a tax already waived refuses the request and writes nothing', function () {
    $setup = wtSetup();
    wtWaive($setup);

    $result = wtWaive($setup);

    expect($result->refusal)->toBe(TaxWaiverResult::REFUSAL_ALREADY_WAIVED)
        ->and(ReservationUnitTaxWaiver::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'reservation.unit_tax_waived')->count())->toBe(1);
});

test('a cancelled unit, another property\'s tax or a tax that cannot be paid elsewhere is refused', function () {
    $setup = wtSetup();
    $otherTax = Tax::factory()->create();

    $foreign = wtWaive($setup, [$otherTax->id]);
    $notWaivable = wtWaive($setup, [$setup['sst']->id, $setup['tourism']->id]);
    $setup['unit']->forceFill(['status' => UnitStatus::Cancelled])->save();
    $cancelled = wtWaive($setup);

    expect($foreign->refusal)->toBe(TaxWaiverResult::REFUSAL_TAX_NOT_FOUND)
        ->and($notWaivable->refusal)->toBe(TaxWaiverResult::REFUSAL_TAX_NOT_WAIVABLE)
        ->and($cancelled->refusal)->toBe(TaxWaiverResult::REFUSAL_UNIT_NOT_WAIVABLE)
        ->and(ReservationUnitTaxWaiver::query()->count())->toBe(0);
});

test('a posted tax on a closed folio is refused and nothing is reversed', function () {
    $setup = wtSetup();
    wtPostNight($setup, '2026-10-12');
    Folio::query()->update(['status' => FolioStatus::Closed]);

    $result = wtWaive($setup);

    expect($result->refusal)->toBe(PostingResult::REFUSAL_FOLIO_NOT_OPEN)
        ->and(ReservationUnitTaxWaiver::query()->count())->toBe(0)
        ->and(wtLiveTaxes())->toBe(['Service Tax' => '8.00', 'Tourism tax' => '10.00']);
});

test('removing a waiver charges the tax on nights not posted yet only', function () {
    $setup = wtSetup();
    wtPostNight($setup, '2026-10-12');
    wtWaive($setup);

    $removed = app(RemoveUnitTaxWaiver::class)($setup['user'], $setup['unit'], $setup['tourism']->id);
    $again = app(RemoveUnitTaxWaiver::class)($setup['user'], $setup['unit'], $setup['tourism']->id);
    wtPostNight($setup, '2026-10-13');

    expect($removed->isRefused())->toBeFalse()
        ->and($again->refusal)->toBe(TaxWaiverResult::REFUSAL_WAIVER_NOT_FOUND)
        ->and(ReservationUnitTaxWaiver::query()->count())->toBe(0)
        ->and(wtLiveTaxes())->toBe(['Service Tax' => '16.00', 'Tourism tax' => '10.00'])
        ->and(Activity::query()->where('event', 'reservation.unit_tax_waiver_removed')->count())->toBe(1);
});

test('front desk waives a tax from the unit panel', function () {
    $setup = wtSetup();

    $this->actingAs($setup['user'])
        ->post(wtUrl($setup), ['tax_ids' => [$setup['tourism']->id], 'reason' => 'paid_elsewhere', 'paid_to' => 'Trip.com', 'reference' => '1128152109837544'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(ReservationUnitTaxWaiver::query()->sole()->tax_id)->toBe($setup['tourism']->id);

    $this->actingAs($setup['user'])
        ->delete(wtUrl($setup, 'tax-waivers/'.$setup['tourism']->id))
        ->assertRedirect();

    expect(ReservationUnitTaxWaiver::query()->count())->toBe(0);
});

test('the waiver form needs who collected the tax and a reference', function () {
    $setup = wtSetup();

    $this->actingAs($setup['user'])
        ->post(wtUrl($setup), ['tax_ids' => [$setup['tourism']->id], 'reason' => 'paid_elsewhere', 'paid_to' => '', 'reference' => ''])
        ->assertSessionHasErrors([
            'paid_to' => 'Enter who collected the tax.',
            'reference' => 'Enter the booking number or the receipt number.',
        ]);

    expect(ReservationUnitTaxWaiver::query()->count())->toBe(0);
});

test('waiving needs the waive taxes permission and the property of the unit', function () {
    $setup = wtSetup();
    $housekeeper = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::ReservationsView])->create();
    $outsider = User::factory()->withRole(DefaultRoleEnum::Owner)->create();
    $payload = ['tax_ids' => [$setup['tourism']->id], 'reason' => 'paid_elsewhere', 'paid_to' => 'Trip.com', 'reference' => '1'];

    $this->actingAs($housekeeper)->post(wtUrl($setup), $payload)->assertForbidden();
    $this->actingAs($outsider)->post(wtUrl($setup), $payload)->assertNotFound();

    expect(ReservationUnitTaxWaiver::query()->count())->toBe(0);
});

test('the unit panel shows the waiver and the form starts with the booking source and reference', function () {
    $setup = wtSetup();
    $source = BookingSource::factory()->for($setup['property'])->ota(name: 'Trip.com')->create();
    $setup['reservation']->forceFill(['booking_source_id' => $source->id, 'external_reference' => '1128152109837544'])->save();
    $levy = Tax::factory()->for($setup['property'])->waivable()->create(['name' => 'Heritage levy']);
    wtWaive($setup);

    $this->actingAs($setup['user'])
        ->get("/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}?unit={$setup['unit']->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('unit.taxWaivers', [['taxId' => $setup['tourism']->id, 'taxName' => 'Tourism tax', 'summary' => 'Paid by Trip.com · 1128152109837544']])
            ->where('unit.taxWaiverForm.taxes', [['value' => (string) $levy->id, 'label' => 'Heritage levy']])
            ->where('unit.taxWaiverForm.paidTo', 'Trip.com')
            ->where('unit.taxWaiverForm.reference', '1128152109837544')
            ->where('unit.actions', fn ($actions): bool => collect($actions)->pluck('key')->contains('waive-tax')));
});

test('without a tax that can be paid elsewhere the menus leave out taxes paid elsewhere', function () {
    $setup = wtSetup();
    $setup['tourism']->update(['can_be_waived' => false]);

    $this->actingAs($setup['user'])
        ->get("/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}?unit={$setup['unit']->id}")
        ->assertInertia(fn ($page) => $page
            ->where('canWaiveTax', false)
            ->where('unit.taxWaiverForm.taxes', [])
            ->where('unit.actions', fn ($actions): bool => ! collect($actions)->pluck('key')->contains('waive-tax')));
});
