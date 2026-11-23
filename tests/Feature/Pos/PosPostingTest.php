<?php

use App\Actions\Pos\PosOutletResult;
use App\Actions\Pos\PosPostingResult;
use App\Actions\Pos\PostPosCharge;
use App\Actions\Pos\ResolvePendingPosPosting;
use App\Actions\Pos\SavePosOutlet;
use App\Actions\Pos\VoidPosCharge;
use App\Data\Pos\PosChargeData;
use App\Data\Pos\PosOutletData;
use App\Data\Pos\PosVoidData;
use App\Data\Pos\ResolvePosPostingData;
use App\Enums\FolioStatus;
use App\Enums\PosPostingStatus;
use App\Enums\PosPostingType;
use App\Enums\UnitStatus;
use App\Models\FolioLine;
use App\Models\PosPosting;
use App\Models\Tax;
use App\Services\Folio\FolioBalance;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../Support/PosSetup.php';

/** @param array<string, mixed> $attributes */
function posCharge(int $unitId, array $attributes = []): PosChargeData
{
    return PosChargeData::from([
        'externalReceiptId' => 'rcpt_1',
        'externalReceiptNumber' => '1042',
        'amount' => '85.50',
        'reservationUnitId' => $unitId,
        'rawPayload' => ['items' => [['name' => 'Nasi Lemak', 'qty' => 2, 'amount' => '85.50']]],
        ...$attributes,
    ]);
}

test('a receipt posts one summary line to the unit folio and the folio gross equals the receipt', function () {
    $setup = posSetup();

    $result = app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id));
    $posting = $result->posting;
    $line = FolioLine::query()->findOrFail($posting->folio_line_id);

    expect($result->isRefused())->toBeFalse()
        ->and($posting->status)->toBe(PosPostingStatus::Posted)
        ->and($posting->property_id)->toBe($setup['property']->id)
        ->and($line->description)->toBe('Main Restaurant — Receipt #1042')
        ->and($line->idempotency_key)->toBe('pos:'.$setup['outlet']->id.':rcpt_1')
        ->and(app(FolioBalance::class)->forFolio($line->folio))->toBe('85.50')
        ->and(Activity::query()->where('event', 'pos.charge_posted')->count())->toBe(1);
});

test('posting the same receipt twice returns the first posting and writes nothing', function () {
    $setup = posSetup();

    $first = app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id));
    $second = app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id));

    expect($second->replayed)->toBeTrue()
        ->and($second->posting->id)->toBe($first->posting->id)
        ->and(PosPosting::query()->count())->toBe(1)
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'pos:%')->where('idempotency_key', 'not like', '%:tax:%')->count())->toBe(1);
});

test('a receipt with no matched room waits as pending review with its payload', function () {
    $setup = posSetup();

    $posting = app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id, ['reservationUnitId' => null, 'failureHint' => 'No linked in-house guest on receipt']))->posting;

    expect($posting->status)->toBe(PosPostingStatus::PendingReview)
        ->and($posting->failure_reason)->toBe('No linked in-house guest on receipt')
        ->and($posting->folio_line_id)->toBeNull()
        ->and($posting->raw_payload['items'][0]['name'])->toBe('Nasi Lemak');
});

test('a receipt for a unit that is not checked in or an inactive outlet waits as pending review', function () {
    $setup = posSetup();
    posUnitStatus($setup['unit'], UnitStatus::Confirmed);

    $notIn = app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id))->posting;

    $setup['outlet']->forceFill(['active' => false])->save();
    $inactive = app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id, ['externalReceiptId' => 'rcpt_2']))->posting;

    expect($notIn->failure_reason)->toBe('unit_not_postable')
        ->and($inactive->failure_reason)->toBe('outlet_inactive')
        ->and(FolioLine::query()->count())->toBe(0);
});

test('a receipt for another property unit is not matched', function () {
    $setup = posSetup();
    $other = posSetup();

    $posting = app(PostPosCharge::class)($setup['outlet'], posCharge($other['unit']->id))->posting;

    expect($posting->status)->toBe(PosPostingStatus::PendingReview)
        ->and($posting->failure_reason)->toBe('room_not_matched')
        ->and($posting->reservation_unit_id)->toBeNull();
});

test('a full void reverses the folio line with no actor and marks both postings', function () {
    $setup = posSetup();
    $charge = app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id))->posting;

    $void = app(VoidPosCharge::class)($setup['outlet'], PosVoidData::from(['externalReceiptId' => 'void_1', 'originalExternalReceiptId' => 'rcpt_1']))->posting;
    $line = FolioLine::query()->findOrFail($charge->folio_line_id);

    expect($void->status)->toBe(PosPostingStatus::Reversed)
        ->and($void->posting_type)->toBe(PosPostingType::Void)
        ->and($charge->fresh()->status)->toBe(PosPostingStatus::Reversed)
        ->and(FolioLine::query()->findOrFail($void->folio_line_id)->reversal_of_id)->toBe($line->id)
        ->and(FolioLine::query()->findOrFail($void->folio_line_id)->user_id)->toBeNull()
        ->and(app(FolioBalance::class)->forFolio($line->folio))->toBe('0.00');
});

test('voiding twice returns the first void', function () {
    $setup = posSetup();
    app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id));
    $data = PosVoidData::from(['externalReceiptId' => 'void_1', 'originalExternalReceiptId' => 'rcpt_1']);

    $first = app(VoidPosCharge::class)($setup['outlet'], $data);
    $second = app(VoidPosCharge::class)($setup['outlet'], $data);

    expect($second->replayed)->toBeTrue()
        ->and($second->posting->id)->toBe($first->posting->id)
        ->and(FolioLine::query()->whereNotNull('reversal_of_id')->count())->toBe(2);
});

test('a partial refund, a missing original and a closed folio go to pending review', function () {
    $setup = posSetup();
    $charge = app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id))->posting;

    $partial = app(VoidPosCharge::class)($setup['outlet'], PosVoidData::from(['externalReceiptId' => 'void_1', 'originalExternalReceiptId' => 'rcpt_1', 'amount' => '10.00']))->posting;
    $missing = app(VoidPosCharge::class)($setup['outlet'], PosVoidData::from(['externalReceiptId' => 'void_2', 'originalExternalReceiptId' => 'nope']))->posting;

    FolioLine::query()->findOrFail($charge->folio_line_id)->folio->forceFill(['status' => FolioStatus::Closed])->save();
    $closed = app(VoidPosCharge::class)($setup['outlet'], PosVoidData::from(['externalReceiptId' => 'void_3', 'originalExternalReceiptId' => 'rcpt_1']))->posting;

    expect($partial->failure_reason)->toBe('partial_refund_manual_review')
        ->and($missing->failure_reason)->toBe('original_not_found')
        ->and($closed->failure_reason)->toBe('folio_closed_cannot_void')
        ->and([$partial->status, $missing->status, $closed->status])->each->toBe(PosPostingStatus::PendingReview)
        ->and($charge->fresh()->status)->toBe(PosPostingStatus::Posted);
});

test('voiding a pending review charge ignores it', function () {
    $setup = posSetup();
    $pending = app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id, ['reservationUnitId' => null]))->posting;

    $void = app(VoidPosCharge::class)($setup['outlet'], PosVoidData::from(['externalReceiptId' => 'void_1', 'originalExternalReceiptId' => 'rcpt_1']))->posting;

    expect($void->status)->toBe(PosPostingStatus::Reversed)
        ->and($pending->fresh()->status)->toBe(PosPostingStatus::Ignored)
        ->and($pending->fresh()->resolution_note)->toBe('Voided before posting');
});

test('staff attach a pending posting to a unit and it posts with the same key', function () {
    $setup = posSetup();
    $pending = app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id, ['reservationUnitId' => null]))->posting;

    $result = app(ResolvePendingPosPosting::class)($setup['actor'], $pending, new ResolvePosPostingData('attach', $setup['unit']->id, null, 'Matched by room'));
    $posted = $result->posting;

    expect($posted->status)->toBe(PosPostingStatus::Posted)
        ->and($posted->resolved_by_user_id)->toBe($setup['actor']->id)
        ->and(FolioLine::query()->findOrFail($posted->folio_line_id)->idempotency_key)->toBe('pos:'.$setup['outlet']->id.':rcpt_1')
        ->and(Activity::query()->where('event', 'pos.posting_resolved')->count())->toBe(1);
});

test('resolving refuses a posting that is not pending, an ignore without a note and an unknown unit', function () {
    $setup = posSetup();
    $posted = app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id))->posting;
    $pending = PosPosting::factory()->create(['pos_outlet_id' => $setup['outlet']->id]);
    $resolve = app(ResolvePendingPosPosting::class);

    expect($resolve($setup['actor'], $posted, new ResolvePosPostingData('ignore', null, null, 'x'))->refusal)->toBe(PosPostingResult::REFUSAL_NOT_PENDING)
        ->and($resolve($setup['actor'], $pending, new ResolvePosPostingData('ignore'))->refusal)->toBe(PosPostingResult::REFUSAL_NOTE_REQUIRED)
        ->and($resolve($setup['actor'], $pending, new ResolvePosPostingData('attach', 999999))->refusal)->toBe(PosPostingResult::REFUSAL_UNIT_NOT_FOUND)
        ->and($pending->fresh()->status)->toBe(PosPostingStatus::PendingReview);
});

test('staff ignore a pending posting with a note', function () {
    $setup = posSetup();
    $pending = PosPosting::factory()->create(['pos_outlet_id' => $setup['outlet']->id]);

    $result = app(ResolvePendingPosPosting::class)($setup['actor'], $pending, new ResolvePosPostingData('ignore', null, null, 'Guest paid cash'));

    expect($result->posting->status)->toBe(PosPostingStatus::Ignored)
        ->and($result->posting->resolution_note)->toBe('Guest paid cash')
        ->and(Activity::query()->where('event', 'pos.posting_ignored')->count())->toBe(1);
});

test('an outlet is refused when its charge item has an exclusive tax', function () {
    $setup = posSetup(inclusiveTax: false);

    $result = app(SavePosOutlet::class)($setup['actor'], $setup['property'], new PosOutletData('Bar', 'bar', $setup['item']->id));

    expect($result->refusal)->toBe(PosOutletResult::REFUSAL_EXCLUSIVE_TAX_NOT_ALLOWED)
        ->and(Activity::query()->where('event', 'pos.outlet_created')->count())->toBe(0);
});

test('an outlet saves with an uppercase code, refuses a duplicate code and logs', function () {
    $setup = posSetup();
    $save = app(SavePosOutlet::class);

    $created = $save($setup['actor'], $setup['property'], new PosOutletData('Bar', 'bar-1', $setup['item']->id));
    $duplicate = $save($setup['actor'], $setup['property'], new PosOutletData('Bar 2', 'BAR-1', $setup['item']->id));
    $updated = $save($setup['actor'], $setup['property'], new PosOutletData('Bar renamed', 'bar-1', $setup['item']->id), $created->outlet);

    expect($created->outlet->code)->toBe('BAR-1')
        ->and($duplicate->refusal)->toBe(PosOutletResult::REFUSAL_CODE_TAKEN)
        ->and($updated->outlet->name)->toBe('Bar renamed')
        ->and(Activity::query()->where('event', 'pos.outlet_created')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'pos.outlet_updated')->count())->toBe(1);
});

test('a refund above the original charge goes to pending review and the charge stays posted', function () {
    $setup = posSetup();
    $charge = app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id))->posting;

    $void = app(VoidPosCharge::class)($setup['outlet'], PosVoidData::from(['externalReceiptId' => 'void_1', 'originalExternalReceiptId' => 'rcpt_1', 'amount' => '100.00']))->posting;

    expect($void->status)->toBe(PosPostingStatus::PendingReview)
        ->and($void->failure_reason)->toBe('refund_amount_mismatch')
        ->and($charge->fresh()->status)->toBe(PosPostingStatus::Posted)
        ->and(FolioLine::query()->whereNotNull('reversal_of_id')->count())->toBe(0);
});

test('a refund equal to the charge reverses it', function () {
    $setup = posSetup();
    app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id));

    $void = app(VoidPosCharge::class)($setup['outlet'], PosVoidData::from(['externalReceiptId' => 'void_1', 'originalExternalReceiptId' => 'rcpt_1', 'amount' => '85.50']))->posting;

    expect($void->status)->toBe(PosPostingStatus::Reversed);
});

test('a tax made exclusive after the outlet was saved sends the receipt to pending review', function () {
    $setup = posSetup();
    $tax = Tax::factory()->for($setup['property'])->create(['rate' => '6.00', 'is_inclusive' => false]);
    $tax->applicableTransactionCodes()->attach($setup['item']->transactionCode);

    $posting = app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id))->posting;

    expect($posting->status)->toBe(PosPostingStatus::PendingReview)
        ->and($posting->failure_reason)->toBe('exclusive_tax_not_allowed')
        ->and(FolioLine::query()->count())->toBe(0);

    $tax->forceFill(['enabled' => false])->save();
    $ok = app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id, ['externalReceiptId' => 'rcpt_2']))->posting;

    expect($ok->status)->toBe(PosPostingStatus::Posted);
});

test('staff cannot attach a pending posting while the charge item has an exclusive tax', function () {
    $setup = posSetup();
    $pending = app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id, ['reservationUnitId' => null]))->posting;
    $tax = Tax::factory()->for($setup['property'])->create(['rate' => '6.00', 'is_inclusive' => false]);
    $tax->applicableTransactionCodes()->attach($setup['item']->transactionCode);

    $result = app(ResolvePendingPosPosting::class)($setup['actor'], $pending, new ResolvePosPostingData('attach', $setup['unit']->id));

    expect($result->refusal)->toBe('exclusive_tax_not_allowed')
        ->and($pending->fresh()->status)->toBe(PosPostingStatus::PendingReview)
        ->and(FolioLine::query()->count())->toBe(0);
});

/**
 * Makes the first lookups of postings miss a row that already exists, then lets the insert hit the unique index. This is
 * what a concurrent duplicate webhook does: its check ran before the other request committed.
 */
function hidePostingsUntilInsert(): void
{
    $hide = true;
    PosPosting::addGlobalScope('race', function (Builder $query) use (&$hide): void {
        if ($hide) {
            $query->whereRaw('1 = 0');
        }
    });
    PosPosting::creating(function () use (&$hide): void {
        $hide = false;
    });
}

test('a concurrent duplicate charge returns the posting that won instead of failing', function () {
    $setup = posSetup();
    $winner = PosPosting::factory()->create(['pos_outlet_id' => $setup['outlet']->id, 'external_receipt_id' => 'rcpt_1']);
    hidePostingsUntilInsert();

    $result = app(PostPosCharge::class)($setup['outlet'], posCharge($setup['unit']->id));

    expect($result->replayed)->toBeTrue()
        ->and($result->posting->id)->toBe($winner->id);
});

test('a concurrent duplicate void returns the void row that won instead of failing', function () {
    $setup = posSetup();
    $winner = PosPosting::factory()->create(['pos_outlet_id' => $setup['outlet']->id, 'external_receipt_id' => 'void_1', 'posting_type' => PosPostingType::Void]);
    hidePostingsUntilInsert();

    $result = app(VoidPosCharge::class)($setup['outlet'], PosVoidData::from(['externalReceiptId' => 'void_1', 'originalExternalReceiptId' => 'rcpt_1']));

    expect($result->replayed)->toBeTrue()
        ->and($result->posting->id)->toBe($winner->id);
});
