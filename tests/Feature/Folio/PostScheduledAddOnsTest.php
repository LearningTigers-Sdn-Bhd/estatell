<?php

use App\Actions\Folio\CreateFolio;
use App\Actions\Folio\PostingResult;
use App\Actions\NightAudit\PostScheduledAddOns;
use App\Enums\FolioStatus;
use App\Enums\TaxRateType;
use App\Enums\UnitStatus;
use App\Models\ArInvoice;
use App\Models\BusinessDate;
use App\Models\ChargeItem;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\UnitAddOn;
use Carbon\CarbonImmutable;

/**
 * A live property trading on 2026-10-12 with a checked-in unit.
 *
 * @return array{property: Property, reservation: Reservation, unit: ReservationUnit, date: CarbonImmutable}
 */
function scheduledAddOnSetup(): array
{
    test()->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
    $property = Property::factory()->live()->create();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    $reservation = Reservation::factory()->create(['property_id' => $property->id]);

    return [
        'property' => $property,
        'reservation' => $reservation,
        'unit' => ReservationUnit::factory()->for($reservation)->checkedIn()->create(['arrival_date' => '2026-10-11', 'departure_date' => '2026-10-14']),
        'date' => CarbonImmutable::parse('2026-10-12'),
    ];
}

function dueAddOn(ReservationUnit $unit, array $attributes = []): UnitAddOn
{
    return UnitAddOn::factory()->create(['reservation_unit_id' => $unit->id, 'service_date' => '2026-10-12', 'quantity' => 2, 'unit_amount' => '20.00', ...$attributes]);
}

test('an add-on due tonight posts with its tax and links its line', function () {
    $setup = scheduledAddOnSetup();
    $addOn = dueAddOn($setup['unit']);
    $tax = Tax::factory()->for($setup['property'])->create(['rate' => '10.00', 'rate_type' => TaxRateType::Percent]);
    $tax->applicableTransactionCodes()->attach($addOn->chargeItem->transaction_code_id);

    $results = app(PostScheduledAddOns::class)(null, $setup['property'], $setup['date']);

    $lines = $results[$addOn->id]->lines;
    expect($lines)->toHaveCount(2)
        ->and($lines[0]->idempotency_key)->toBe('addon:'.$addOn->id)
        ->and($lines[0]->description)->toBe($addOn->chargeItem->name)
        ->and($lines[0]->quantity)->toBe(2)
        ->and($lines[0]->amount)->toBe('40.00')
        ->and($lines[0]->source_id)->toBe($addOn->id)
        ->and($lines[0]->user_id)->toBeNull()
        ->and($lines[0]->posting_date->toDateString())->toBe('2026-10-12')
        ->and($lines[1]->amount)->toBe('4.00')
        ->and($addOn->refresh()->folio_line_id)->toBe($lines[0]->id);
});

test('only add-ons due on the business date and not posted post', function () {
    $setup = scheduledAddOnSetup();
    $due = dueAddOn($setup['unit']);
    dueAddOn($setup['unit'], ['service_date' => '2026-10-13']);
    dueAddOn($setup['unit'], ['service_date' => null]);
    UnitAddOn::factory()->posted()->create(['reservation_unit_id' => $setup['unit']->id, 'service_date' => '2026-10-12']);
    $other = ReservationUnit::factory()->for($setup['reservation'])->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14']);
    dueAddOn($other);
    $cancelled = ReservationUnit::factory()->for($setup['reservation'])->create(['arrival_date' => '2026-10-12', 'departure_date' => '2026-10-14', 'status' => UnitStatus::Cancelled]);
    dueAddOn($cancelled);

    $results = app(PostScheduledAddOns::class)(null, $setup['property'], $setup['date']);

    expect(array_keys($results))->toBe([$due->id]);
});

test('posting twice writes nothing the second time', function () {
    $setup = scheduledAddOnSetup();
    dueAddOn($setup['unit']);
    $post = app(PostScheduledAddOns::class);

    $post(null, $setup['property'], $setup['date']);
    $count = FolioLine::query()->count();
    $second = $post(null, $setup['property'], $setup['date']);

    expect($second)->toBe([])->and(FolioLine::query()->count())->toBe($count);
});

test('one add-on that cannot post does not stop the others', function () {
    $setup = scheduledAddOnSetup();
    $foreign = dueAddOn($setup['unit']);
    Tax::factory()->for($setup['property'])->create(['rate' => '6.00', 'rate_type' => TaxRateType::Percent, 'foreign_guests_only' => true])
        ->applicableTransactionCodes()->attach($foreign->chargeItem->transaction_code_id);
    Guest::query()->whereKey($setup['reservation']->booker_guest_id)->update(['nationality_code' => null]);
    $plain = dueAddOn($setup['unit'], ['charge_item_id' => ChargeItem::factory()->create(['property_id' => $setup['property']->id])->id]);

    $results = app(PostScheduledAddOns::class)(null, $setup['property'], $setup['date']);

    expect($results[$foreign->id]->refusal)->toBe('nationality_required')
        ->and($results[$plain->id]->isRefused())->toBeFalse()
        ->and($foreign->refresh()->folio_line_id)->toBeNull()
        ->and($plain->refresh()->folio_line_id)->not->toBeNull();
});

test('a closed folio refuses its add-on and writes nothing', function () {
    $setup = scheduledAddOnSetup();
    $addOn = dueAddOn($setup['unit']);
    app(CreateFolio::class)($setup['reservation'], $setup['unit'])->forceFill(['status' => FolioStatus::Closed])->save();

    $results = app(PostScheduledAddOns::class)(null, $setup['property'], $setup['date']);

    expect($results[$addOn->id]->refusal)->toBe(PostingResult::REFUSAL_FOLIO_NOT_OPEN)->and(FolioLine::query()->count())->toBe(0);
});

test('an add-on whose folio is settled to the city ledger is refused with its own code', function () {
    $setup = scheduledAddOnSetup();
    $addOn = dueAddOn($setup['unit']);
    $folio = app(CreateFolio::class)($setup['reservation'], $setup['unit']);
    $folio->forceFill(['status' => FolioStatus::Closed])->save();
    ArInvoice::factory()->create(['property_id' => $setup['property']->id, 'folio_id' => $folio->id]);

    $results = app(PostScheduledAddOns::class)(null, $setup['property'], $setup['date']);

    expect($results[$addOn->id]->refusal)->toBe(PostingResult::REFUSAL_FOLIO_SETTLED)->and(FolioLine::query()->count())->toBe(0);
});

test('a date that is not the business date posts nothing', function () {
    $setup = scheduledAddOnSetup();
    dueAddOn($setup['unit'], ['service_date' => '2026-10-13']);

    expect(app(PostScheduledAddOns::class)(null, $setup['property'], CarbonImmutable::parse('2026-10-13')))->toBe([])
        ->and(FolioLine::query()->count())->toBe(0);
});

test('a child price row posts with its age group in the description', function () {
    $setup = scheduledAddOnSetup();
    $group = PropertyAgeGroup::factory()->create(['property_id' => $setup['property']->id, 'label' => 'Child', 'min_age' => 4, 'max_age' => 11]);
    $addOn = dueAddOn($setup['unit'], ['property_age_group_id' => $group->id, 'quantity' => 1, 'unit_amount' => '15.00']);

    $results = app(PostScheduledAddOns::class)(null, $setup['property'], $setup['date']);

    expect($results[$addOn->id]->lines[0]->description)->toBe($addOn->chargeItem->name.' · Child 4–11');
});
