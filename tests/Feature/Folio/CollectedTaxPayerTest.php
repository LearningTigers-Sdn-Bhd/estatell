<?php

use App\Actions\Folio\PostingResult;
use App\Actions\Folio\ReverseLine;
use App\Actions\Folio\TransferLines;
use App\Actions\NightAudit\PostRoomCharges;
use App\Actions\Reservations\ChangeNightPrice;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\BusinessDate;
use App\Models\Company;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioRoutingRule;
use App\Models\Guest;
use App\Models\PropertySetting;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Models\User;
use App\Services\Folio\ScheduledFolioRows;
use App\Services\FrontDesk\CheckInCollection;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * A checked-in unit of 2 nights at 300.00 for a foreign guest, on a reservation whose company pays the room charges
 * on the Master folio, and a RM10 tourism tax per room-night collected at check-in.
 *
 * @return array{user: User, unit: ReservationUnit, reservation: Reservation, company: Company, master: Folio, tax: Tax}
 */
function collectedTaxSetup(bool $companyPaysTax = false): array
{
    test()->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
    $user = User::factory()->owner()->create();
    $property = $user->property;
    $property->forceFill(['status' => 'live'])->save();
    PropertySetting::factory()->for($property)->create();
    BusinessDate::factory()->create(['property_id' => $property->id, 'date' => '2026-10-12']);
    $room = TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);
    TransactionCode::factory()->system(SystemTransactionCode::Discount)->create(['property_id' => $property->id]);
    $tax = Tax::factory()->for($property)->create([
        'name' => 'Tourism tax', 'rate_type' => TaxRateType::Flat, 'rate' => '10.00',
        'foreign_guests_only' => true, 'collect_at_check_in' => true, 'enabled' => true,
    ]);
    $tax->applicableTransactionCodes()->attach($room);
    $company = Company::factory()->for($property)->create(['pays_collected_taxes' => $companyPaysTax]);
    $guest = Guest::factory()->create(['property_id' => $property->id, 'nationality_code' => 'CN']);
    $reservation = Reservation::factory()->create(['property_id' => $property->id, 'company_id' => $company->id, 'booker_guest_id' => $guest->id]);
    $unit = ReservationUnit::factory()->for($reservation)->withNights()->checkedIn()->create([
        'arrival_date' => '2026-10-12',
        'departure_date' => '2026-10-14',
        'adults' => 2,
    ]);
    UnitNight::query()->where('reservation_unit_id', $unit->id)->update(['base_amount' => '300.00', 'amount' => '300.00']);
    $master = Folio::factory()->master()->create(['property_id' => $property->id, 'reservation_id' => $reservation->id, 'payer_company_id' => $company->id]);
    FolioRoutingRule::factory()->create(['reservation_id' => $reservation->id, 'category' => TransactionCategory::Room, 'target_folio_id' => $master->id]);

    return ['user' => $user, 'unit' => $unit, 'reservation' => $reservation, 'company' => $company, 'master' => $master, 'tax' => $tax];
}

function collectedTaxPostNight(array $setup, string $date = '2026-10-12'): void
{
    app(PostRoomCharges::class)(null, $setup['unit'], CarbonImmutable::parse($date));
}

function collectedTaxUnitFolio(array $setup): ?Folio
{
    return Folio::query()->where('reservation_unit_id', $setup['unit']->id)->where('type', FolioType::Unit)->first();
}

function collectedTaxBalance(?Folio $folio): string
{
    return (string) BigDecimal::of($folio === null ? '0' : (string) FolioLine::query()->where('folio_id', $folio->id)->sum('amount'))->toScale(2);
}

test('the room night goes to the company and its tourism tax to the guest folio, as a child of the room line', function () {
    $setup = collectedTaxSetup();

    collectedTaxPostNight($setup);
    collectedTaxPostNight($setup);

    $room = FolioLine::query()->where('kind', TransactionKind::Charge)->sole();
    $tax = FolioLine::query()->where('kind', TransactionKind::Tax)->sole();
    $unitFolio = collectedTaxUnitFolio($setup);

    expect($room->folio_id)->toBe($setup['master']->id)
        ->and($tax->folio_id)->toBe($unitFolio->id)
        ->and($tax->parent_line_id)->toBe($room->id)
        ->and($tax->amount)->toBe('10.00')
        ->and(collectedTaxBalance($setup['master']))->toBe('300.00')
        ->and(collectedTaxBalance($unitFolio))->toBe('10.00');
});

test('a company that pays tourism tax gets it on its folio with the room', function () {
    $setup = collectedTaxSetup(companyPaysTax: true);

    collectedTaxPostNight($setup);

    expect(FolioLine::query()->where('kind', TransactionKind::Tax)->sole()->folio_id)->toBe($setup['master']->id)
        ->and(collectedTaxUnitFolio($setup))->toBeNull();
});

test('Collect now offers the tourism tax the guest pays, and hides it when the company pays it', function () {
    $setup = collectedTaxSetup();

    $offer = app(CheckInCollection::class)->for($setup['unit']);

    expect($offer->taxesHidden)->toBeFalse()
        ->and($offer->taxesTotal)->toBe('20.00')
        ->and($offer->billHidden)->toBeTrue();

    $setup['company']->update(['pays_collected_taxes' => true]);

    expect(app(CheckInCollection::class)->for($setup['unit']->fresh())->taxesHidden)->toBeTrue();
});

test('the scheduled tourism tax shows on the guest folio and the room night on the master folio', function () {
    $setup = collectedTaxSetup();
    $unitFolio = Folio::factory()->forUnit($setup['unit'])->create(['property_id' => $setup['unit']->property_id, 'reservation_id' => $setup['reservation']->id]);
    $reservation = $setup['reservation']->fresh(['booker']);

    $blocks = app(ScheduledFolioRows::class)->forReservation($reservation->property()->with('settings')->first(), $reservation, []);
    $byFolio = collect($blocks)->groupBy('target')->map(fn ($group) => $group->flatMap(fn (array $block): array => array_map(fn ($row): string => (string) $row->amount, $block['rows']))->all());

    expect($byFolio[$setup['master']->id])->toBe(['300.00', '300.00'])
        ->and($byFolio[$unitFolio->id])->toBe(['10.00', '10.00']);
});

test('reversing the room night reverses its tourism tax on the guest folio, and a closed guest folio refuses it', function () {
    $setup = collectedTaxSetup();
    collectedTaxPostNight($setup);
    $room = FolioLine::query()->where('kind', TransactionKind::Charge)->sole();
    $unitFolio = collectedTaxUnitFolio($setup);
    $unitFolio->update(['status' => FolioStatus::Closed]);

    expect(app(ReverseLine::class)($setup['user'], $room)->refusal)->toBe(PostingResult::REFUSAL_FOLIO_NOT_OPEN)
        ->and(FolioLine::query()->count())->toBe(2);

    $unitFolio->update(['status' => FolioStatus::Open]);
    $result = app(ReverseLine::class)($setup['user'], $room);

    expect($result->isRefused())->toBeFalse()
        ->and(collectedTaxBalance($setup['master']))->toBe('0.00')
        ->and(collectedTaxBalance($unitFolio))->toBe('0.00')
        ->and(FolioLine::query()->where('reversal_of_id', FolioLine::query()->where('kind', TransactionKind::Tax)->whereNull('reversal_of_id')->value('id'))->value('folio_id'))->toBe($unitFolio->id);
});

test('moving the room night to another folio keeps its tourism tax on the guest folio', function () {
    $setup = collectedTaxSetup();
    collectedTaxPostNight($setup);
    $room = FolioLine::query()->where('kind', TransactionKind::Charge)->sole();
    $unitFolio = collectedTaxUnitFolio($setup);
    $thirdParty = Folio::factory()->thirdParty()->create(['property_id' => $setup['unit']->property_id, 'reservation_id' => $setup['reservation']->id, 'payer_company_id' => $setup['company']->id]);

    $result = app(TransferLines::class)($setup['user'], [$room], $thirdParty);

    $copy = FolioLine::query()->where('folio_id', $thirdParty->id)->sole();
    $taxCopy = FolioLine::query()->where('parent_line_id', $copy->id)->sole();
    expect($result->isRefused())->toBeFalse()
        ->and($taxCopy->folio_id)->toBe($unitFolio->id)
        ->and(collectedTaxBalance($setup['master']))->toBe('0.00')
        ->and(collectedTaxBalance($thirdParty))->toBe('300.00')
        ->and(collectedTaxBalance($unitFolio))->toBe('10.00');
});

test('a corrected night price posts its tourism tax on the guest folio again', function () {
    $setup = collectedTaxSetup();
    collectedTaxPostNight($setup);
    BusinessDate::query()->update(['date' => '2026-10-13']);
    [$first] = $setup['unit']->nights()->orderBy('date')->get()->all();

    $result = app(ChangeNightPrice::class)($setup['user'], $setup['unit'], [$first], '250.00', 'Agent rate');

    expect($result->isRefused())->toBeFalse()
        ->and(collectedTaxBalance($setup['master']))->toBe('250.00')
        ->and(collectedTaxBalance(collectedTaxUnitFolio($setup)))->toBe('10.00')
        ->and(FolioLine::query()->where('idempotency_key', 'like', 'night:'.$first->id.':fix:1:tax:%')->sole()->folio_id)->toBe(collectedTaxUnitFolio($setup)->id);
});

test('the company form saves who pays tourism tax', function () {
    $setup = collectedTaxSetup();
    $property = $setup['user']->property;

    $this->actingAs($setup['user'])
        ->put(route('finance.city-ledger.companies.update', [$property->code, $setup['company'], 'credit']), [
            'direct_bill_enabled' => '1', 'pays_collected_taxes' => '1', 'credit_limit' => '', 'payment_terms_days' => '',
        ])
        ->assertSessionHasNoErrors();

    expect($setup['company']->refresh()->pays_collected_taxes)->toBeTrue();

    $this->put(route('finance.city-ledger.companies.update', [$property->code, $setup['company'], 'credit']), [
        'direct_bill_enabled' => '1', 'credit_limit' => '', 'payment_terms_days' => '',
    ])->assertSessionHasNoErrors();

    expect($setup['company']->refresh()->pays_collected_taxes)->toBeFalse();
});
