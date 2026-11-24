<?php

require_once __DIR__.'/KioskSetup.php';
require_once __DIR__.'/ConciergeSetup.php';

use App\Enums\AiScope;
use App\Enums\BookingSourceType;
use App\Enums\SystemTransactionCode;
use App\Features\AiAssistant;
use App\Features\Concierge;
use App\Models\ConciergeQuote;
use App\Models\Property;
use App\Models\PropertyAiScope;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TransactionCode;
use Database\Factories\BookingSourceFactory;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;

/**
 * A Concierge property with the AI assistant and its Concierge and RoomQuotes scopes on, and the walk-in switch OFF, as
 * quotes do not need it. One free room (101) of Deluxe Double for 2026-11-02 to 2026-11-04 (two nights), on three plans:
 * `staff` at 80 a night (not sold online, the cheapest), `roomOnly` at 100 and `breakfast` at 130 (both sold online).
 *
 * @return array{property: Property, type: RoomType, room: Room, staff: RatePlan, roomOnly: RatePlan, breakfast: RatePlan}
 */
function conciergeQuoteSetup(): array
{
    $setup = kioskStaySetup();
    $property = $setup['property'];
    Feature::for($property)->activate(Concierge::class);
    Feature::for($property)->activate(AiAssistant::class);
    PropertyAiScope::factory()->for($property)->create(['scope' => AiScope::Concierge]);
    PropertyAiScope::factory()->for($property)->create(['scope' => AiScope::RoomQuotes]);
    $property->settings->forceFill(['concierge_walk_in_enabled' => false, 'security_deposit_amount' => '0.00', 'auto_assign_rooms' => true, 'card_required_for_check_in' => false])->save();

    $type = $setup['unit']->roomType;
    $type->forceFill(['max_adults' => 4, 'max_children' => 2])->save();
    $room = Room::factory()->for($property)->create(['room_type_id' => $type->id, 'number' => '101']);
    $plans = [
        'staff' => quotePlan($property, $type, 'Staff rate', '80.00', online: false),
        'roomOnly' => quotePlan($property, $type, 'Room only', '100.00', online: true),
        'breakfast' => quotePlan($property, $type, 'With breakfast', '130.00', online: true),
    ];

    BookingSourceFactory::systemFor($property->id, BookingSourceType::WalkIn);
    TransactionCode::factory()->system(SystemTransactionCode::RoomRevenue)->create(['property_id' => $property->id]);

    return ['property' => $property, 'type' => $type, 'room' => $room, ...$plans];
}

function quotePlan(Property $property, RoomType $type, string $name, string $amount, bool $online): RatePlan
{
    $plan = RatePlan::factory()->for($property)->state(['name' => $name, 'bookable_online' => $online])->create();
    $type->ratePlans()->attach($plan);

    foreach (['2026-11-02', '2026-11-03'] as $date) {
        Rate::factory()->create(['rate_plan_id' => $plan->id, 'room_type_id' => $type->id, 'date' => $date, 'amount' => $amount]);
    }

    return $plan;
}

/**
 * A saved quote for the setup's room on `$plan`, 2026-11-02 to 2026-11-04 for two adults.
 *
 * @param  array<string, mixed>  $setup
 * @param  array<string, mixed>  $attributes
 */
function savedConciergeQuote(array $setup, RatePlan $plan, array $attributes = []): ConciergeQuote
{
    return ConciergeQuote::factory()->create([
        'property_id' => $setup['property']->id,
        'room_type_id' => $setup['type']->id,
        'rate_plan_id' => $plan->id,
        'arrival_date' => '2026-11-02',
        'departure_date' => '2026-11-04',
        'adults' => 2,
        'quoted_total' => '260.00',
        ...$attributes,
    ]);
}

/**
 * Books a quote the way its page does, with the guest's details.
 *
 * @param  array<string, mixed>  $identity
 */
function bookQuote(Property $property, ConciergeQuote $quote, array $identity = []): TestResponse
{
    return test()->post("/stay/{$property->code}/quote/{$quote->token}", conciergeIdentityBody($identity));
}
