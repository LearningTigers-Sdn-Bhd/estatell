<?php

use App\Actions\Concierge\CreateConciergeQuote;
use App\Data\Kiosk\KioskWalkInData;
use App\Enums\AiScope;
use App\Events\ConciergeQuoteBooked;
use App\Features\AiAssistant;
use App\Features\Concierge;
use App\Models\ConciergeQuote;
use App\Models\Guest;
use App\Models\KioskWalkInHold;
use App\Models\Property;
use App\Models\PropertyAiScope;
use App\Models\PropertySetting;
use App\Models\Rate;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\UnitNight;
use App\Services\FrontDesk\SelfCheckInPayment;
use App\Services\Kiosk\KioskWalkIn;
use App\Services\Kiosk\KioskWalkInOffer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/ConciergeQuoteSetup.php';

test('walk-ins still get the cheapest plan while quotes list only plans sold online', function () {
    $setup = conciergeQuoteSetup();
    $search = new KioskWalkInData(CarbonImmutable::parse('2026-11-02'), 2, 2);
    $walkIn = app(KioskWalkIn::class);
    $plans = fn (array $offers): array => array_map(fn (KioskWalkInOffer $offer): string => $offer->ratePlan->name.' '.$offer->total->getAmount(), $offers);

    expect($plans($walkIn->available($setup['property'], $search)))->toBe(['Staff rate 160.00'])
        ->and($plans($walkIn->available($setup['property'], $search, $setup['type']->id, $setup['breakfast']->id)))->toBe(['With breakfast 260.00'])
        ->and($plans($walkIn->quotable($setup['property'], $search)))->toBe(['Room only 200.00', 'With breakfast 260.00']);
});

test('a quote saves the plan price for 24 hours and books nothing', function () {
    $setup = conciergeQuoteSetup();

    $result = app(CreateConciergeQuote::class)($setup['property'], $setup['type']->id, $setup['breakfast']->id, CarbonImmutable::parse('2026-11-02'), CarbonImmutable::parse('2026-11-04'), 2);

    expect($result->refusal)->toBeNull()
        ->and($result->quote)->rate_plan_id->toBe($setup['breakfast']->id)
        ->quoted_total->toBe('260.00')
        ->expires_at->toEqual(now()->addHours(24))
        ->and(strlen($result->quote->token))->toBe(40)
        ->and(Reservation::query()->count())->toBe(1);
});

test('a quote is refused outside the booking window, on a plan not sold online, or on bad dates', function (string $arrival, string $departure, string $plan, string $refusal) {
    $setup = conciergeQuoteSetup();

    $result = app(CreateConciergeQuote::class)($setup['property'], $setup['type']->id, $setup[$plan]->id, CarbonImmutable::parse($arrival), CarbonImmutable::parse($departure), 2);

    expect($result->refusal)->toBe($refusal)
        ->and(ConciergeQuote::query()->count())->toBe(0);
})->with([
    'more than 6 months ahead' => ['2027-04-20', '2027-04-22', 'roomOnly', 'outside_window'],
    'in the past' => ['2026-10-10', '2026-10-12', 'roomOnly', 'outside_window'],
    'plan not sold online' => ['2026-11-02', '2026-11-04', 'staff', 'no_room_available'],
    'departure before arrival' => ['2026-11-04', '2026-11-02', 'roomOnly', 'invalid_dates'],
]);

test('the quote page prices the quote again and shows a changed price', function () {
    $setup = conciergeQuoteSetup();
    $quote = quoteFor($setup, $setup['breakfast'], ['quoted_total' => '240.00']);

    $this->get("/stay/{$setup['property']->code}/quote/{$quote->token}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('concierge/quote')
            ->where('quote.ratePlanName', 'With breakfast')
            ->where('quote.quotedTotal', '240.00')
            ->where('quote.total', '260.00')
            ->where('quote.expired', false));
});

test('the quote page shows an expired quote or a room no longer free without a price', function () {
    $setup = conciergeQuoteSetup();
    $expired = quoteFor($setup, $setup['breakfast'], ['expires_at' => now()->subMinute()]);
    $offline = quoteFor($setup, $setup['roomOnly']);
    $setup['roomOnly']->forceFill(['bookable_online' => false])->save();

    $this->get("/stay/{$setup['property']->code}/quote/{$expired->token}")
        ->assertInertia(fn (Assert $page) => $page->where('quote.expired', true)->where('quote.total', null));
    $this->get("/stay/{$setup['property']->code}/quote/{$offline->token}")
        ->assertInertia(fn (Assert $page) => $page->where('quote.expired', false)->where('quote.total', null));
});

test('the quote routes answer 404 for another property, or while the AI assistant or RoomQuotes is off', function () {
    $setup = conciergeQuoteSetup();
    $other = ['property' => Property::factory()->create()];
    PropertySetting::factory()->for($other['property'])->create();
    Feature::for($other['property'])->activate(Concierge::class);
    $quote = quoteFor($setup, $setup['breakfast']);

    $this->get("/stay/{$other['property']->code}/quote/{$quote->token}")->assertNotFound();
    bookQuote($other['property'], $quote)->assertNotFound();

    PropertyAiScope::query()->where('property_id', $setup['property']->id)->where('scope', AiScope::RoomQuotes)->update(['enabled' => false]);
    $this->get("/stay/{$setup['property']->code}/quote/{$quote->token}")->assertNotFound();

    PropertyAiScope::query()->where('property_id', $setup['property']->id)->update(['enabled' => true]);
    Feature::for($setup['property'])->deactivate(AiAssistant::class);
    $this->get("/stay/{$setup['property']->code}/quote/{$quote->token}")->assertNotFound();
    bookQuote($setup['property'], $quote)->assertNotFound();

    expect(ConciergeQuote::query()->whereNotNull('reservation_id')->count())->toBe(0);
});

test('booking a quote books and holds its plan with the walk-in switch off, then opens the stay on payment', function () {
    Event::fake([ConciergeQuoteBooked::class]);
    $setup = conciergeQuoteSetup();
    $quote = quoteFor($setup, $setup['breakfast'], ['ai_turn_id' => null]);

    $location = bookQuote($setup['property'], $quote)->assertSessionHasNoErrors()->assertRedirect()->headers->get('Location');

    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);
    expect($location)->toStartWith(url('/c/'))
        ->and($stay)->rate_plan_id->toBe($setup['breakfast']->id)
        ->arrival_date->toDateString()->toBe('2026-11-02')
        ->departure_date->toDateString()->toBe('2026-11-04')
        ->adults->toBe(2)
        ->and(UnitNight::query()->where('reservation_unit_id', $stay->id)->count())->toBe(2)
        ->and($quote->fresh()->reservation_id)->toBe($stay->reservation_id);
    Event::assertDispatched(ConciergeQuoteBooked::class, fn (ConciergeQuoteBooked $event): bool => $event->reservationId === $stay->reservation_id && $event->propertyId === $setup['property']->id);

    $this->get($location)->assertInertia(fn (Assert $page) => $page->where('stay.step', 'payment')->where('stay.shortfall', '260.00')->where('stay.fromQuote', true));
});

test('a used quote books once and its link then opens the stay page', function () {
    $setup = conciergeQuoteSetup();
    $quote = quoteFor($setup, $setup['breakfast']);
    bookQuote($setup['property'], $quote)->assertSessionHasNoErrors();

    bookQuote($setup['property'], $quote)->assertRedirect("/stay/{$setup['property']->code}/quote/{$quote->token}");

    expect(ReservationUnit::query()->where('rate_plan_id', $setup['breakfast']->id)->count())->toBe(1);
    expect($this->get("/stay/{$setup['property']->code}/quote/{$quote->token}")->assertRedirect()->headers->get('Location'))->toStartWith(url('/c/'));
});

test('an expired quote, or a plan no longer sold online, books nothing', function () {
    $setup = conciergeQuoteSetup();
    $expired = quoteFor($setup, $setup['breakfast'], ['expires_at' => now()->subMinute()]);
    $offline = quoteFor($setup, $setup['roomOnly']);
    $setup['roomOnly']->forceFill(['bookable_online' => false])->save();

    bookQuote($setup['property'], $expired)->assertRedirect("/stay/{$setup['property']->code}/quote/{$expired->token}");
    bookQuote($setup['property'], $offline)->assertRedirect("/stay/{$setup['property']->code}/quote/{$offline->token}");

    expect(KioskWalkInHold::query()->count())->toBe(0)
        ->and(Reservation::query()->count())->toBe(1);
});

test('a returning guest books a quote by email for their own profile', function () {
    $setup = conciergeQuoteSetup();
    $returning = Guest::factory()->create(['property_id' => $setup['property']->id, 'full_name' => 'Nora Lim', 'email' => 'nora@example.com']);
    $quote = quoteFor($setup, $setup['roomOnly']);

    $this->post("/stay/{$setup['property']->code}/quote/{$quote->token}/returning", ['contact' => 'nora@example.com'])->assertSessionHasNoErrors()->assertRedirect();

    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);
    expect($stay->rate_plan_id)->toBe($setup['roomOnly']->id)
        ->and($stay->reservation->booker_guest_id)->toBe($returning->id)
        ->and($quote->fresh()->reservation_id)->toBe($stay->reservation_id);
});

test('a walk-in stay keeps the walk-in wording on its payment step', function () {
    $setup = conciergeQuoteSetup();
    $setup['property']->settings->forceFill(['concierge_walk_in_enabled' => true])->save();
    Rate::factory()->create(['rate_plan_id' => $setup['staff']->id, 'room_type_id' => $setup['type']->id, 'date' => '2026-10-14', 'amount' => '80.00']);

    $location = $this->post("/stay/{$setup['property']->code}/walk-in", ['adults' => 1, 'nights' => 1, 'room_type_id' => $setup['type']->id, ...conciergeIdentityBody()])->headers->get('Location');

    $this->get($location)->assertInertia(fn (Assert $page) => $page->where('stay.fromQuote', false));
});

test('a quote stay asks for the whole stay with its taxes and the deposit, not only Collect now', function () {
    $setup = conciergeQuoteSetup();
    $setup['property']->settings->forceFill(['security_deposit_amount' => '100.00'])->save();
    $roomRevenue = TransactionCode::query()->where('property_id', $setup['property']->id)->sole();
    $tax = Tax::factory()->for($setup['property'])->create(['name' => 'Tourism tax', 'rate' => '8.00', 'is_inclusive' => false, 'collect_at_check_in' => true]);
    $tax->applicableTransactionCodes()->attach($roomRevenue);
    $quote = quoteFor($setup, $setup['breakfast']);

    $location = bookQuote($setup['property'], $quote)->assertSessionHasNoErrors()->headers->get('Location');
    $stay = ReservationUnit::query()->findOrFail(KioskWalkInHold::query()->sole()->reservation_unit_id);

    $this->get($location)->assertInertia(fn (Assert $page) => $page->where('stay.step', 'payment')->where('stay.shortfall', '380.80'));
    expect(app(SelfCheckInPayment::class)->shortfall($stay))->toBe('120.80');
});
