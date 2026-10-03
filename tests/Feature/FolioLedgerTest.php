<?php

use App\Actions\Guests\AnonymizeGuest;
use App\Actions\Guests\MergeGuests;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\User;
use App\Services\Folio\FolioBalance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

function ownerAndGuest(): array
{
    $owner = User::factory()->owner()->create();

    return [$owner, Guest::factory()->for($owner->property)->create()];
}

test('the factories build an open master folio with a charge', function () {
    $folio = Folio::factory()->create();
    $line = FolioLine::factory()->for($folio)->create();

    expect($folio->type)->toBe(FolioType::Master)
        ->and($folio->status)->toBe(FolioStatus::Open)
        ->and($folio->number)->not->toBeEmpty()
        ->and($line->folio->is($folio))->toBeTrue()
        ->and($line->amount)->toBe('100.00')
        ->and($line->created_at)->not->toBeNull();
});

test('the balance is the sum of signed lines and a reversal brings it back to 0', function () {
    $folio = Folio::factory()->create();
    $balance = app(FolioBalance::class);

    expect($balance->forFolio($folio))->toBe('0.00');

    $charge = FolioLine::factory()->for($folio)->create(['amount' => '250.00', 'unit_amount' => '250.00']);
    FolioLine::factory()->for($folio)->payment('100.00')->create();
    expect($balance->forFolio($folio))->toBe('150.00');

    FolioLine::factory()->reversalOf($charge)->create();
    expect($balance->forFolio($folio))->toBe('-100.00');
});

test('posting the same idempotency key twice is rejected', function () {
    $folio = Folio::factory()->create();
    FolioLine::factory()->for($folio)->create(['idempotency_key' => 'night:1']);

    expect(fn () => DB::transaction(fn () => FolioLine::factory()->for($folio)->create(['idempotency_key' => 'night:1'])))
        ->toThrow(QueryException::class);

    expect(FolioLine::query()->count())->toBe(1);
});

test('the database refuses to update or delete a ledger line', function () {
    $line = FolioLine::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('folio_lines')->where('id', $line->id)->update(['amount' => '1.00'])))
        ->toThrow(QueryException::class, 'immutable ledger');
    expect(fn () => DB::transaction(fn () => DB::table('folio_lines')->where('id', $line->id)->delete()))
        ->toThrow(QueryException::class, 'immutable ledger');

    expect($line->fresh()->amount)->toBe('100.00');
});

test('a folio cannot have a guest and a company payer', function () {
    $folio = Folio::factory()->create();
    $guest = Guest::factory()->for($folio->property)->create();

    expect(fn () => DB::transaction(fn () => $folio->forceFill(['payer_guest_id' => $guest->id, 'payer_company_id' => 1])->save()))
        ->toThrow(QueryException::class);
});

test('only a unit folio has a reservation unit', function () {
    expect(fn () => DB::transaction(fn () => Folio::factory()->forUnit()->create(['type' => FolioType::Master])))
        ->toThrow(QueryException::class);

    $unitFolio = Folio::factory()->forUnit()->create();
    expect($unitFolio->type)->toBe(FolioType::Unit)->and($unitFolio->reservationUnit)->not->toBeNull();
});

test('folio statuses allow only close, reopen and void', function () {
    expect(FolioStatus::Open->canTransitionTo(FolioStatus::Closed))->toBeTrue()
        ->and(FolioStatus::Closed->canTransitionTo(FolioStatus::Open))->toBeTrue()
        ->and(FolioStatus::Open->canTransitionTo(FolioStatus::Voided))->toBeTrue()
        ->and(FolioStatus::Closed->canTransitionTo(FolioStatus::Voided))->toBeFalse()
        ->and(FolioStatus::Voided->canTransitionTo(FolioStatus::Open))->toBeFalse();
});

test('a merge moves the folios the merged guest pays and logs the count', function () {
    [$owner, $survivor] = ownerAndGuest();
    $merged = Guest::factory()->for($owner->property)->create();
    $other = Guest::factory()->for($owner->property)->create();
    $payerFolio = Folio::factory()->for($owner->property)->paidBy($merged)->create();
    $otherFolio = Folio::factory()->for($owner->property)->paidBy($other)->create();
    FolioLine::factory()->for($payerFolio)->create();

    expect(app(MergeGuests::class)($owner, $survivor, $merged))->toBeTrue();

    expect($payerFolio->fresh()->payer_guest_id)->toBe($survivor->id)
        ->and($otherFolio->fresh()->payer_guest_id)->toBe($other->id)
        ->and(FolioLine::query()->count())->toBe(1)
        ->and(Activity::query()->where('event', 'guest.merged')->sole()->properties['folios_moved'])->toBe(1);
});

test('anonymizing is refused for a guest with an open balance and allowed once it is 0', function () {
    [$owner, $guest] = ownerAndGuest();
    $folio = Folio::factory()->for($owner->property)->paidBy($guest)->create();
    FolioLine::factory()->for($folio)->create();

    expect(app(AnonymizeGuest::class)($owner, $guest))->toBeFalse()
        ->and($guest->fresh()->anonymized_at)->toBeNull()
        ->and(Activity::query()->where('event', 'guest.anonymized')->exists())->toBeFalse();

    FolioLine::factory()->for($folio)->payment('100.00')->create();

    expect(app(AnonymizeGuest::class)($owner, $guest))->toBeTrue()
        ->and($guest->fresh()->anonymized_at)->not->toBeNull();
});

test('balances of another payer or another property do not block anonymizing', function () {
    [$owner, $guest] = ownerAndGuest();
    $folio = Folio::factory()->for($owner->property)->create();
    FolioLine::factory()->for($folio)->create();
    $otherPropertyFolio = Folio::factory()->paidBy($guest)->create();
    FolioLine::factory()->for($otherPropertyFolio)->create();

    expect(app(AnonymizeGuest::class)($owner, $guest))->toBeTrue();
});

test('the open balance check does not run a query for every folio', function () {
    [$owner, $guest] = ownerAndGuest();
    Folio::factory()->for($owner->property)->paidBy($guest)->count(3)->create();

    DB::enableQueryLog();
    app(FolioBalance::class)->guestHasOpenBalance($guest);

    expect(DB::getQueryLog())->toHaveCount(1);
});
