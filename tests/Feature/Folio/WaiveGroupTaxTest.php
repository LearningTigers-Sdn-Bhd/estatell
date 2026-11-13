<?php

use App\Actions\Folio\GroupTaxWaiverResult;
use App\Actions\Folio\PostingResult;
use App\Actions\Folio\TaxWaiverResult;
use App\Actions\Folio\WaiveGroupTax;
use App\Actions\Folio\WaiveUnitTax;
use App\Actions\NightAudit\PostRoomCharges;
use App\Enums\FolioStatus;
use App\Enums\PermissionEnum;
use App\Enums\TaxWaiverReason;
use App\Enums\UnitStatus;
use App\Models\Folio;
use App\Models\ReservationUnit;
use App\Models\ReservationUnitTaxWaiver;
use App\Models\UnitNight;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/TaxWaiverSetup.php';

/**
 * The tax waiver setup with a second checked-in unit on the same reservation.
 *
 * @return array<string, mixed>
 */
function wgSetup(): array
{
    $setup = wtSetup();
    $second = ReservationUnit::factory()->for($setup['reservation'])->withNights()->checkedIn()->create([
        'arrival_date' => '2026-10-12',
        'departure_date' => '2026-10-14',
        'adults' => 1,
    ]);
    UnitNight::query()->where('reservation_unit_id', $second->id)->update(['base_amount' => '100.00', 'amount' => '100.00']);

    return [...$setup, 'second' => $second];
}

/**
 * @param  list<int>  $unitIds
 */
function wgWaive(array $setup, array $unitIds): GroupTaxWaiverResult
{
    return app(WaiveGroupTax::class)($setup['user'], $setup['reservation'], $unitIds, [$setup['tourism']->id], TaxWaiverReason::PaidElsewhere, 'Trip.com', '1128000012345678');
}

function wgUrl(array $setup): string
{
    return "/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}/tax-waivers";
}

test('waiving for several rooms waives each one, reverses their posted nights and skips a room that has it', function () {
    $setup = wgSetup();
    $third = ReservationUnit::factory()->for($setup['reservation'])->withNights()->checkedIn()->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);
    app(WaiveUnitTax::class)($setup['user'], $third, [$setup['tourism']->id], TaxWaiverReason::GuestReceipt, null, 'TTX-1');
    wtPostNight($setup, '2026-10-12');
    app(PostRoomCharges::class)(null, $setup['second'], CarbonImmutable::parse('2026-10-12'));

    $result = wgWaive($setup, [$setup['unit']->id, $setup['second']->id, $third->id]);

    expect($result->isRefused())->toBeFalse()
        ->and([$result->waived, $result->skipped, $result->reversed])->toBe([2, 1, 2])
        ->and(ReservationUnitTaxWaiver::query()->where('reason', TaxWaiverReason::PaidElsewhere)->orderBy('reservation_unit_id')->pluck('reservation_unit_id')->all())->toBe([$setup['unit']->id, $setup['second']->id])
        ->and(ReservationUnitTaxWaiver::query()->where('reservation_unit_id', $third->id)->sole()->reference)->toBe('TTX-1')
        ->and(wtLiveTaxes())->toBe(['Service Tax' => '16.00'])
        ->and(Activity::query()->where('event', 'reservation.unit_tax_waived')->count())->toBe(3);
});

test('a refusal on one room rolls back every room', function () {
    $setup = wgSetup();
    wtPostNight($setup, '2026-10-12');
    Folio::query()->where('reservation_unit_id', $setup['unit']->id)->update(['status' => FolioStatus::Closed]);

    $result = wgWaive($setup, [$setup['second']->id, $setup['unit']->id]);

    expect($result->refusal)->toBe(PostingResult::REFUSAL_FOLIO_NOT_OPEN)
        ->and(ReservationUnitTaxWaiver::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'reservation.unit_tax_waived')->count())->toBe(0);
});

test('a cancelled room or a room of another reservation is refused', function () {
    $setup = wgSetup();
    $elsewhere = ReservationUnit::factory()->checkedIn()->create(['property_id' => $setup['property']->id]);

    $foreign = wgWaive($setup, [$setup['unit']->id, $elsewhere->id]);
    $setup['second']->forceFill(['status' => UnitStatus::Cancelled])->save();
    $cancelled = wgWaive($setup, [$setup['unit']->id, $setup['second']->id]);

    expect($foreign->refusal)->toBe(TaxWaiverResult::REFUSAL_UNIT_NOT_WAIVABLE)
        ->and($cancelled->refusal)->toBe(TaxWaiverResult::REFUSAL_UNIT_NOT_WAIVABLE)
        ->and(ReservationUnitTaxWaiver::query()->count())->toBe(0);
});

test('rooms that all have the tax waived already are refused', function () {
    $setup = wgSetup();
    wgWaive($setup, [$setup['unit']->id, $setup['second']->id]);

    $again = wgWaive($setup, [$setup['unit']->id, $setup['second']->id]);

    expect($again->refusal)->toBe(TaxWaiverResult::REFUSAL_ALREADY_WAIVED)
        ->and(ReservationUnitTaxWaiver::query()->count())->toBe(2);
});

test('front desk waives a tax for the chosen rooms from More', function () {
    $setup = wgSetup();

    $this->actingAs($setup['user'])
        ->post(wgUrl($setup), ['unit_ids' => [$setup['unit']->id, $setup['second']->id], 'tax_ids' => [$setup['tourism']->id], 'reason' => 'paid_elsewhere', 'paid_to' => 'Trip.com', 'reference' => '1128000012345678'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(ReservationUnitTaxWaiver::query()->count())->toBe(2);
});

test('the group form needs at least one room', function () {
    $setup = wgSetup();

    $this->actingAs($setup['user'])
        ->post(wgUrl($setup), ['unit_ids' => [], 'tax_ids' => [$setup['tourism']->id], 'reason' => 'paid_elsewhere', 'paid_to' => 'Trip.com', 'reference' => '1'])
        ->assertSessionHasErrors(['unit_ids' => 'Choose at least one room.']);

    expect(ReservationUnitTaxWaiver::query()->count())->toBe(0);
});

test('the group waiver needs the waive taxes permission', function () {
    $setup = wgSetup();
    $viewer = User::factory()->for($setup['property'])->withPermissions([PermissionEnum::ReservationsView])->create();

    $this->actingAs($viewer)
        ->post(wgUrl($setup), ['unit_ids' => [$setup['unit']->id], 'tax_ids' => [$setup['tourism']->id], 'reason' => 'paid_elsewhere', 'paid_to' => 'Trip.com', 'reference' => '1'])
        ->assertForbidden();

    expect(ReservationUnitTaxWaiver::query()->count())->toBe(0);
});

test('the group form options list the taxes that can be paid elsewhere when asked for', function () {
    $setup = wgSetup();
    $setup['reservation']->forceFill(['external_reference' => '1128000012345678'])->save();

    $this->actingAs($setup['user'])
        ->get("/p/{$setup['property']->code}/reservations/{$setup['reservation']->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('canWaiveTax', true)
            ->missing('taxWaiverOptions')
            ->reloadOnly('taxWaiverOptions', fn (Assert $reload) => $reload
                ->where('taxWaiverOptions.taxes', [
                    ['value' => (string) $setup['tourism']->id, 'label' => 'Tourism tax'],
                ])
                ->where('taxWaiverOptions.reference', '1128000012345678')));
});
