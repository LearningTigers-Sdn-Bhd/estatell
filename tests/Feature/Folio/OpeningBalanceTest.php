<?php

require_once __DIR__.'/../Support/MigratedStaySetup.php';

use App\Actions\Folio\ChangeOpeningBalance;
use App\Actions\Folio\PostingResult;
use App\Actions\Folio\PostOpeningBalance;
use App\Enums\FolioStatus;
use App\Enums\OldFolioMode;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Services\Folio\FolioBalance;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * A migrated stay with a 1,080.00 opening balance. `unit` and `folio` are the first unit and its folio, `line` the opening line.
 *
 * @return array<string, mixed>
 */
function openingBalanceSetup(int $window = 60, string $amount = '1080.00'): array
{
    $setup = migratedStaySetup($window);
    $result = createMigratedStay($setup, amount: $amount);
    $unit = $result->reservation->units()->sole();
    $folio = Folio::query()->where('reservation_unit_id', $unit->id)->sole();

    return [...$setup, 'reservation' => $result->reservation, 'unit' => $unit, 'folio' => $folio, 'line' => FolioLine::query()->where('idempotency_key', 'like', 'opening:%')->first()];
}

function openingUrl(array $setup): string
{
    return route('reservations.folios.lines.opening-balance', ['property' => $setup['property']->code, 'reservation' => $setup['reservation'], 'folio' => $setup['folio'], 'line' => $setup['line']]);
}

test('an opening balance is one signed line with no tax and the old folio number on the folio', function () {
    $setup = openingBalanceSetup();
    $line = $setup['line'];

    expect((string) $line->amount)->toBe('1080.00')
        ->and($line->kind->value)->toBe('adjustment')
        ->and($line->transactionCode->code)->toBe('OPENING_BAL')
        ->and($line->children()->count())->toBe(0)
        ->and($setup['folio']->refresh()->legacy_number)->toBe('F1203')
        ->and(Activity::query()->where('event', 'folio.opening_balance_posted')->count())->toBe(1);
});

test('no number gives a plain description, and a second opening balance on a folio is refused', function () {
    $setup = migratedStaySetup();
    $result = createMigratedStay($setup, number: null);
    $unit = $result->reservation->units()->sole();

    expect(FolioLine::query()->where('idempotency_key', 'like', 'opening:%')->sole()->description)->toBe('Opening balance')
        ->and(app(PostOpeningBalance::class)($setup['user'], $unit, '10.00', 'F1')->refusal)->toBe(PostingResult::REFUSAL_OPENING_BALANCE_EXISTS)
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'opening:%')->count())->toBe(1);
});

test('Change opening balance reverses the line and posts the new one in one go', function () {
    $setup = openingBalanceSetup();
    $before = app(FolioBalance::class)->forFolio($setup['folio']);

    $result = app(ChangeOpeningBalance::class)($setup['user'], $setup['line'], '-200.50', 'Old PMS corrected');

    $lines = FolioLine::query()->where('folio_id', $setup['folio']->id)->where('transaction_code_id', $setup['line']->transaction_code_id)->orderBy('id')->get();

    expect($result->isRefused())->toBeFalse()
        ->and($lines->map(fn ($line): string => (string) $line->amount)->all())->toBe(['1080.00', '-1080.00', '-200.50'])
        ->and($lines[1]->reversal_of_id)->toBe($lines[0]->id)
        ->and($lines[2]->idempotency_key)->toBe('opening:'.$setup['folio']->id.':2')
        ->and($lines[2]->description)->toBe('Opening balance · old folio F1203')
        ->and($lines[2]->posting_date->toDateString())->toBe('2026-10-14')
        ->and($lines[2]->service_date->toDateString())->toBe('2026-10-10')
        ->and(app(FolioBalance::class)->forFolio($setup['folio']))->toBe(number_format((float) $before - 1080 - 200.5, 2, '.', ''));

    $log = Activity::query()->where('event', 'folio.opening_balance_changed')->sole();
    expect($log->properties['reason'])->toBe('Old PMS corrected')
        ->and($log->properties['new_amount'])->toBe('-200.50');
});

test('a zero amount only reverses, and the same line cannot be changed twice', function () {
    $setup = openingBalanceSetup();
    $change = app(ChangeOpeningBalance::class);

    $zero = $change($setup['user'], $setup['line'], '0', 'Paid in the old system');
    $again = $change($setup['user'], $setup['line'], '5.00', 'Second click');

    expect($zero->isRefused())->toBeFalse()
        ->and($zero->lines)->toHaveCount(1)
        ->and($again->refusal)->toBe(PostingResult::REFUSAL_ALREADY_REVERSED)
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'opening:%')->count())->toBe(1);
});

test('Change opening balance is refused outside the window, for another line, a bad amount or a closed folio', function () {
    $setup = openingBalanceSetup();
    $change = app(ChangeOpeningBalance::class);
    $nightLine = FolioLine::query()->where('idempotency_key', 'like', 'night:%')->whereNull('parent_line_id')->firstOrFail();
    $lines = FolioLine::query()->count();

    expect($change($setup['user'], $nightLine, '5.00', 'x')->refusal)->toBe(PostingResult::REFUSAL_NOT_OPENING_BALANCE)
        ->and($change($setup['user'], $setup['line'], 'abc', 'x')->refusal)->toBe(PostingResult::REFUSAL_OPENING_AMOUNT_INVALID)
        ->and($change($setup['user'], $setup['line'], '5.00', ' ')->refusal)->toBe(PostingResult::REFUSAL_REASON_REQUIRED);

    $setup['folio']->forceFill(['status' => FolioStatus::Closed])->save();
    expect($change($setup['user'], $setup['line'], '5.00', 'x')->refusal)->toBe(PostingResult::REFUSAL_FOLIO_NOT_OPEN);
    $setup['folio']->forceFill(['status' => FolioStatus::Open])->save();

    $setup['property']->settings->forceFill(['migration_window_days' => 3])->save();
    expect($change($setup['user'], $setup['line'], '5.00', 'x')->refusal)->toBe(PostingResult::REFUSAL_MIGRATION_WINDOW_CLOSED)
        ->and(FolioLine::query()->count())->toBe($lines)
        ->and(Activity::query()->where('event', 'folio.opening_balance_changed')->exists())->toBeFalse();
});

test('the route changes the opening balance for folio.manual and shows the error after the window', function () {
    $setup = openingBalanceSetup();

    $this->actingAs($setup['user'])->post(openingUrl($setup), ['amount' => '1,200.00', 'reason' => 'Corrected'])
        ->assertSessionHasNoErrors();
    expect(FolioLine::query()->where('idempotency_key', 'opening:'.$setup['folio']->id.':2')->sole()->amount)->toBe('1200.00');

    $this->post(openingUrl($setup), ['amount' => 'abc', 'reason' => 'Corrected'])->assertSessionHasErrors('amount');
    $this->post(openingUrl($setup), ['amount' => '1.00', 'reason' => ''])->assertSessionHasErrors('reason');

    $line = FolioLine::query()->where('idempotency_key', 'opening:'.$setup['folio']->id.':2')->sole();
    $setup['property']->settings->forceFill(['migration_window_days' => 3])->save();
    $this->post(route('reservations.folios.lines.opening-balance', ['property' => $setup['property']->code, 'reservation' => $setup['reservation'], 'folio' => $setup['folio'], 'line' => $line]), ['amount' => '1.00', 'reason' => 'Late'])
        ->assertSessionHasErrors('form');
});

test('Change opening balance needs folio.manual', function () {
    $setup = openingBalanceSetup();
    actionsRoleWith($setup['user'], ['reservations.manage', 'folio.reverse']);

    $this->actingAs($setup['user'])->post(openingUrl($setup), ['amount' => '1.00', 'reason' => 'x'])->assertForbidden();
    expect(FolioLine::query()->where('idempotency_key', 'like', 'opening:%')->count())->toBe(1);
});

test('the folio tab offers Change opening balance on the live opening line inside the window only', function () {
    $setup = openingBalanceSetup();
    $url = route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']]);

    $this->actingAs($setup['user'])->get($url)->assertInertia(function (Assert $page) use ($setup) {
        $rows = collect($page->toArray()['props']['folio']['rows'])->where('lineId', $setup['line']->id);
        $page->has('manualLines.'.$setup['line']->id)->where('manualLines.'.$setup['line']->id.'.total', '1080.00');
        expect($rows->first()['actions'])->toContain('change_opening_balance');
    });

    app(ChangeOpeningBalance::class)($setup['user'], $setup['line'], '0', 'Paid');
    $this->get($url)->assertInertia(fn (Assert $page) => $page->missing('manualLines.'.$setup['line']->id));
});

test('the folio tab offers no Change opening balance after the window', function () {
    $setup = openingBalanceSetup();
    $setup['property']->settings->forceFill(['migration_window_days' => 3])->save();

    $this->actingAs($setup['user'])->get(route('reservations.folio', ['property' => $setup['property']->code, 'reservation' => $setup['reservation']]))
        ->assertInertia(fn (Assert $page) => $page->missing('manualLines.'.$setup['line']->id));
});

test('a copy line by line stay has no opening line to change', function () {
    $setup = migratedStaySetup();
    $result = createMigratedStay($setup, mode: OldFolioMode::CopyLineByLine);

    expect(FolioLine::query()->where('idempotency_key', 'like', 'opening:%')->count())->toBe(0)
        ->and($result->reservation->folios()->whereNotNull('legacy_number')->count())->toBe(0);
});
