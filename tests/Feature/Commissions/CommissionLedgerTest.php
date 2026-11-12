<?php

use App\Actions\Commissions\PostCommissionEntry;
use App\Actions\Commissions\ReverseCommissionEntry;
use App\Data\Commissions\CommissionEntryInputData;
use App\Enums\CommissionEntryType;
use App\Enums\CommissionService;
use App\Models\CommissionEntry;
use App\Models\Property;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

function commissionInput(array $overrides = []): CommissionEntryInputData
{
    return new CommissionEntryInputData(...[
        'type' => CommissionEntryType::Adjustment,
        'amount' => '-12.50',
        'dueDate' => CarbonImmutable::parse('2026-10-14'),
        'idempotencyKey' => 'adjustment:test',
        'reason' => 'Late fee',
        ...$overrides,
    ]);
}

test('posting the same key twice writes one entry', function () {
    $property = Property::factory()->create();

    $first = app(PostCommissionEntry::class)($property, commissionInput());
    $second = app(PostCommissionEntry::class)($property, commissionInput(['amount' => '-99.00']));

    expect($second->is($first))->toBeTrue()
        ->and(CommissionEntry::query()->count())->toBe(1)
        ->and($first->fresh()->amount)->toBe('-12.50');
});

test('the same key on another property is a separate entry', function () {
    app(PostCommissionEntry::class)(Property::factory()->create(), commissionInput());
    app(PostCommissionEntry::class)(Property::factory()->create(), commissionInput());

    expect(CommissionEntry::query()->count())->toBe(2);
});

test('a commission entry needs its service, base and rate', function () {
    $reservation = Reservation::factory()->create();

    app(PostCommissionEntry::class)($reservation->property, commissionInput([
        'type' => CommissionEntryType::Commission,
        'reservationId' => $reservation->id,
        'reason' => null,
    ]));
})->throws(QueryException::class, 'commission_entries_commission_check');

test('a reversal negates the entry and lands on the given due date', function () {
    $reservation = Reservation::factory()->create();
    $entry = app(PostCommissionEntry::class)($reservation->property, commissionInput([
        'type' => CommissionEntryType::Commission,
        'amount' => '-45.00',
        'reservationId' => $reservation->id,
        'service' => CommissionService::BookingEngine,
        'baseAmount' => '450.00',
        'rate' => '10.00',
        'idempotencyKey' => 'commission:booking_engine:'.$reservation->id.':1',
        'reason' => null,
    ]));

    $reversal = app(ReverseCommissionEntry::class)($entry, CarbonImmutable::parse('2026-11-20'));

    expect($reversal->reverses_entry_id)->toBe($entry->id)
        ->and($reversal->amount)->toBe('45.00')
        ->and($reversal->base_amount)->toBe('-450.00')
        ->and($reversal->rate)->toBe('10.00')
        ->and($reversal->service)->toBe(CommissionService::BookingEngine)
        ->and($reversal->due_date->toDateString())->toBe('2026-11-20')
        ->and($reversal->idempotency_key)->toBe('reversal:'.$entry->id);
});

test('an entry is reversed only once, and a reversal is never reversed', function () {
    $entry = app(PostCommissionEntry::class)(Property::factory()->create(), commissionInput());
    $reverse = app(ReverseCommissionEntry::class);

    $reversal = $reverse($entry, null);

    expect($reverse($entry, null))->toBeNull()
        ->and($reverse($reversal, null))->toBeNull()
        ->and(CommissionEntry::query()->count())->toBe(2);
});
