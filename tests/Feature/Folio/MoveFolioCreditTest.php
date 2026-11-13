<?php

use App\Actions\Folio\MoveFolioCredit;
use App\Actions\Folio\PostingResult;
use App\Actions\Folio\TransferLines;
use App\Enums\FolioStatus;
use App\Enums\PermissionEnum;
use App\Enums\SystemTransactionCode;
use App\Enums\TransactionKind;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\TransactionCode;
use App\Models\User;
use App\Services\EInvoice\ReportableLines;
use Brick\Math\BigDecimal;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * A group booking whose Master folio holds RM 50 of credit (a payment with no charge), and two unit folios that each
 * owe RM 10 of tourism tax.
 *
 * @return array{user: User, reservation: Reservation, master: Folio, f1: Folio, f2: Folio}
 */
function creditMoveSetup(): array
{
    $user = User::factory()->owner()->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-14']);
    TransactionCode::factory()->system(SystemTransactionCode::FolioTransfer)->create(['property_id' => $property->id]);
    $guest = Guest::factory()->create(['property_id' => $property->id]);
    $reservation = Reservation::factory()->create(['property_id' => $property->id, 'booker_guest_id' => $guest->id]);
    $master = Folio::factory()->master()->create(['property_id' => $property->id, 'reservation_id' => $reservation->id]);
    FolioLine::factory()->payment('50.00')->create(['folio_id' => $master->id]);
    $unitFolio = function () use ($property, $reservation): Folio {
        $unit = ReservationUnit::factory()->for($reservation)->checkedIn()->create();
        $folio = Folio::factory()->forUnit($unit)->create(['property_id' => $property->id, 'reservation_id' => $reservation->id]);
        FolioLine::factory()->create(['folio_id' => $folio->id, 'kind' => TransactionKind::Tax, 'description' => 'Tourism tax', 'unit_amount' => '10.00', 'amount' => '10.00']);

        return $folio;
    };

    return ['user' => $user, 'reservation' => $reservation, 'master' => $master, 'f1' => $unitFolio(), 'f2' => $unitFolio()];
}

function creditMoveBalance(Folio $folio): string
{
    return (string) BigDecimal::of((string) FolioLine::query()->where('folio_id', $folio->id)->sum('amount'))->toScale(2);
}

/**
 * @param  list<array{folio_id: int, amount: string}>  $moves
 */
function creditMoveRun(array $setup, array $moves, ?string $key = null): PostingResult
{
    return app(MoveFolioCredit::class)($setup['user'], $setup['master'], $moves, null, $key ?? (string) Str::uuid());
}

function creditMoveUrl(array $setup, string $name = 'reservations.folios.credit-moves.store', array $extra = []): string
{
    return route($name, ['property' => $setup['user']->property->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['master']->id, ...$extra]);
}

test('moving credit posts one pair of payment lines per folio and settles every balance', function () {
    $setup = creditMoveSetup();

    $this->actingAs($setup['user'])
        ->post(creditMoveUrl($setup), [
            'moves' => [['folio_id' => $setup['f1']->id, 'amount' => '10.00'], ['folio_id' => $setup['f2']->id, 'amount' => '10.00']],
            'note' => 'TTx paid with the agent transfer',
            'idempotency_key' => (string) Str::uuid(),
        ])
        ->assertSessionHasNoErrors();

    $moves = FolioLine::query()->whereHas('transactionCode', fn ($codes) => $codes->where('system_key', SystemTransactionCode::FolioTransfer->value))->get();
    $f1Pair = $moves->where('transfer_group_id', $moves->firstWhere('folio_id', $setup['f1']->id)->transfer_group_id);

    expect(creditMoveBalance($setup['master']))->toBe('-30.00')
        ->and(creditMoveBalance($setup['f1']))->toBe('0.00')
        ->and(creditMoveBalance($setup['f2']))->toBe('0.00')
        ->and($moves)->toHaveCount(4)
        ->and($moves->every(fn (FolioLine $line): bool => $line->kind === TransactionKind::Payment))->toBeTrue()
        ->and($f1Pair->pluck('amount', 'folio_id')->all())->toBe([$setup['master']->id => '10.00', $setup['f1']->id => '-10.00'])
        ->and($moves->firstWhere('folio_id', $setup['f1']->id)->description)->toBe('Credit from Master')
        ->and(Payment::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'folio.credit_moved')->sole()->properties['note'])->toBe('TTx paid with the agent transfer');
});

test('a move changes no e-invoice figure: each folio still reports only its own charges', function () {
    $setup = creditMoveSetup();

    creditMoveRun($setup, [['folio_id' => $setup['f1']->id, 'amount' => '10.00']]);

    expect((string) app(ReportableLines::class)->gross($setup['master']->fresh())->toScale(2))->toBe('0.00')
        ->and((string) app(ReportableLines::class)->gross($setup['f1']->fresh())->toScale(2))->toBe('10.00');
});

test('a move can be partial', function () {
    $setup = creditMoveSetup();

    expect(creditMoveRun($setup, [['folio_id' => $setup['f1']->id, 'amount' => '4.00']])->isRefused())->toBeFalse()
        ->and(creditMoveBalance($setup['master']))->toBe('-46.00')
        ->and(creditMoveBalance($setup['f1']))->toBe('6.00');
});

test('a refused move writes and logs nothing', function (Closure $moves, string $refusal) {
    $setup = creditMoveSetup();

    expect(creditMoveRun($setup, $moves($setup))->refusal)->toBe($refusal)
        ->and(FolioLine::query()->count())->toBe(3)
        ->and(Activity::query()->where('event', 'folio.credit_moved')->count())->toBe(0);
})->with([
    'nothing ticked' => [fn (array $setup): array => [], PostingResult::REFUSAL_CREDIT_MOVE_EMPTY],
    'more than a folio owes' => [fn (array $setup): array => [['folio_id' => $setup['f1']->id, 'amount' => '10.01']], PostingResult::REFUSAL_CREDIT_MOVE_ABOVE_OWED],
    'an amount of 0' => [fn (array $setup): array => [['folio_id' => $setup['f1']->id, 'amount' => '0']], PostingResult::REFUSAL_CREDIT_MOVE_AMOUNT_INVALID],
    'the source itself' => [fn (array $setup): array => [['folio_id' => $setup['master']->id, 'amount' => '5.00']], PostingResult::REFUSAL_TRANSFER_INVALID],
    'a folio of another reservation' => [fn (array $setup): array => [['folio_id' => Folio::factory()->create(['property_id' => $setup['master']->property_id])->id, 'amount' => '5.00']], PostingResult::REFUSAL_DIFFERENT_RESERVATION],
    'a closed folio' => [function (array $setup): array {
        $setup['f1']->update(['status' => FolioStatus::Closed]);

        return [['folio_id' => $setup['f1']->id, 'amount' => '5.00']];
    }, PostingResult::REFUSAL_FOLIO_NOT_OPEN],
]);

test('a move cannot take more than the source holds', function () {
    $setup = creditMoveSetup();
    FolioLine::factory()->create(['folio_id' => $setup['master']->id, 'amount' => '45.00', 'unit_amount' => '45.00']);

    expect(creditMoveRun($setup, [['folio_id' => $setup['f1']->id, 'amount' => '6.00']])->refusal)->toBe(PostingResult::REFUSAL_CREDIT_MOVE_ABOVE_CREDIT);
});

test('a repeat with the same key moves nothing again', function () {
    $setup = creditMoveSetup();
    $key = (string) Str::uuid();
    $moves = [['folio_id' => $setup['f1']->id, 'amount' => '10.00']];

    creditMoveRun($setup, $moves, $key);
    $again = creditMoveRun($setup, $moves, $key);

    expect($again->isRefused())->toBeFalse()
        ->and(FolioLine::query()->count())->toBe(5)
        ->and(creditMoveBalance($setup['master']))->toBe('-40.00');
});

test('undo reverses both lines of a move, once', function () {
    $setup = creditMoveSetup();
    creditMoveRun($setup, [['folio_id' => $setup['f1']->id, 'amount' => '10.00']]);
    $in = FolioLine::query()->where('folio_id', $setup['f1']->id)->where('amount', '-10.00')->sole();
    $url = route('reservations.folios.lines.credit-move.undo', ['property' => $setup['user']->property->code, 'reservation' => $setup['reservation']->id, 'folio' => $setup['f1']->id, 'line' => $in->id]);

    $this->actingAs($setup['user'])->post($url)->assertInertiaFlash('toast.type', 'success');

    expect(creditMoveBalance($setup['master']))->toBe('-50.00')
        ->and(creditMoveBalance($setup['f1']))->toBe('10.00')
        ->and(Activity::query()->where('event', 'folio.credit_move_undone')->count())->toBe(1);

    $this->post($url)->assertInertiaFlash('toast.type', 'error');

    expect(FolioLine::query()->count())->toBe(7);
});

test('Move charges never moves a credit move line', function () {
    $setup = creditMoveSetup();
    creditMoveRun($setup, [['folio_id' => $setup['f1']->id, 'amount' => '10.00']]);
    $in = FolioLine::query()->where('folio_id', $setup['f1']->id)->where('amount', '-10.00')->sole();

    expect(app(TransferLines::class)($setup['user'], [$in], $setup['f2'])->refusal)->toBe(PostingResult::REFUSAL_LINE_NOT_TRANSFERABLE);
});

test('moving credit needs payment.record and stays in its property', function () {
    $setup = creditMoveSetup();
    $clerk = User::factory()->for($setup['user']->property)->withPermissions([PermissionEnum::ReservationsView])->create();
    $body = ['moves' => [['folio_id' => $setup['f1']->id, 'amount' => '10.00']], 'idempotency_key' => (string) Str::uuid()];

    $this->actingAs($clerk)->post(creditMoveUrl($setup), $body)->assertForbidden();

    $other = creditMoveSetup();
    $this->actingAs($setup['user'])
        ->post(route('reservations.folios.credit-moves.store', ['property' => $setup['user']->property->code, 'reservation' => $other['reservation']->id, 'folio' => $other['master']->id]), $body)
        ->assertNotFound();

    expect(FolioLine::query()->whereNotNull('transfer_group_id')->count())->toBe(0);
});

test('the folio page offers Move credit on a folio in credit, and Undo move on its lines', function () {
    $setup = creditMoveSetup();
    $page = fn (Folio $folio) => $this->actingAs($setup['user'])->get(route('reservations.folio', ['property' => $setup['user']->property->code, 'reservation' => $setup['reservation']->id, 'folio' => $folio->id]))->assertOk();

    $page($setup['master'])->assertInertia(fn (Assert $inertia) => $inertia
        ->where('folio.creditMove.credit', '50.00')
        ->has('folio.creditMove.targets', 2)
        ->where('folio.creditMove.targets.0.folioId', $setup['f1']->id)
        ->where('folio.creditMove.targets.0.owes', '10.00'));
    $page($setup['f1'])->assertInertia(fn (Assert $inertia) => $inertia->where('folio.creditMove', null));

    creditMoveRun($setup, [['folio_id' => $setup['f1']->id, 'amount' => '10.00']]);

    $page($setup['f1'])->assertInertia(fn (Assert $inertia) => $inertia
        ->where('folio.rows', fn ($rows): bool => collect($rows)->contains(fn (array $row): bool => $row['description'] === 'Credit from Master' && $row['actions'] === ['undo_credit_move'])));
});
