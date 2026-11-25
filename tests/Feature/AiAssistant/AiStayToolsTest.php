<?php

use App\Ai\Agents\PropertyAssistant;
use App\Ai\Tools\HandOver;
use App\Ai\Tools\MakeGuestRequest;
use App\Ai\Tools\PropertyFacts;
use App\Ai\Tools\ReadMyStay;
use App\Enums\AiPlace;
use App\Enums\AiScope;
use App\Enums\GuestRequestRefusal;
use App\Enums\GuestRequestSource;
use App\Enums\GuestRequestType;
use App\Features\AiAssistant;
use App\Models\AiThread;
use App\Models\GuestRequest;
use App\Models\Property;
use App\Models\PropertyAiScope;
use App\Models\ReservationUnit;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request as ToolRequest;
use Laravel\Pennant\Feature;

require_once __DIR__.'/../Support/AiKeySetup.php';
require_once __DIR__.'/../Support/ConciergeSetup.php';

/**
 * Turn the AI assistant on with the Concierge, StayDetails and GuestRequests scopes, and open a stay thread for `$unit`.
 */
function stayToolsThread(Property $property, ReservationUnit $unit): AiThread
{
    Feature::for($property)->activate(AiAssistant::class);

    foreach ([AiScope::Concierge, AiScope::StayDetails, AiScope::GuestRequests] as $scope) {
        PropertyAiScope::factory()->for($property)->create(['scope' => $scope]);
    }

    giveAiKey($property);

    return AiThread::factory()->for($property)->create(['place' => AiPlace::ConciergeStay, 'reservation_unit_id' => $unit->id]);
}

/**
 * @return list<class-string>
 */
function stayToolClasses(AiThread $thread): array
{
    return collect(PropertyAssistant::make(thread: $thread->fresh())->tools())->map(fn (object $tool): string => $tool::class)->all();
}

test('a stay thread gets each stay tool only while its scope is on, and a lobby thread never does', function () {
    ['property' => $property, 'unit' => $unit] = conciergeInStaySetup();
    $thread = stayToolsThread($property, $unit);

    expect(stayToolClasses($thread))->toBe([PropertyFacts::class, ReadMyStay::class, MakeGuestRequest::class, HandOver::class]);

    PropertyAiScope::query()->where('property_id', $property->id)->where('scope', AiScope::GuestRequests)->update(['enabled' => false]);
    expect(stayToolClasses($thread))->toBe([PropertyFacts::class, ReadMyStay::class, HandOver::class]);

    $lobby = AiThread::factory()->for($property)->create(['place' => AiPlace::ConciergeLobby]);
    expect(stayToolClasses($lobby))->toBe([PropertyFacts::class, HandOver::class]);
});

test('ReadMyStay reads the thread\'s own stay with its room once checked in', function () {
    ['property' => $property, 'unit' => $unit] = conciergeInStaySetup();
    $thread = stayToolsThread($property, $unit);
    $tool = app(ReadMyStay::class, ['thread' => $thread]);

    $stay = json_decode((string) $tool->handle(new ToolRequest([])), true);

    expect($stay)->found->toBeTrue()
        ->arrival_date->toBe('2026-10-12')
        ->departure_date->toBe('2026-10-14')
        ->nights->toBe(2)
        ->room_number->toBe('101')
        ->balance_due->toBe('0.00')
        ->currency->toBe('MYR');
});

test('ReadMyStay gives no room number before check-in, and nothing for a thread with no stay', function () {
    ['property' => $property, 'unit' => $unit] = conciergeSetup();
    $thread = stayToolsThread($property, $unit);

    $stay = json_decode((string) app(ReadMyStay::class, ['thread' => $thread])->handle(new ToolRequest([])), true);
    expect($stay)->found->toBeTrue()->room_number->toBeNull();

    $lobby = AiThread::factory()->for($property)->create(['place' => AiPlace::ConciergeLobby]);
    $none = json_decode((string) app(ReadMyStay::class, ['thread' => $lobby])->handle(new ToolRequest([])), true);
    expect($none['found'])->toBeFalse();
});

test('MakeGuestRequest opens a Concierge request for the thread\'s own stay', function () {
    ['property' => $property, 'unit' => $unit] = conciergeInStaySetup();
    $thread = stayToolsThread($property, $unit);
    $tool = app(MakeGuestRequest::class, ['thread' => $thread]);

    $sent = json_decode((string) $tool->handle(new ToolRequest(['type' => 'housekeeping', 'details' => ' Two more towels, please. '])), true);
    $request = GuestRequest::query()->sole();

    expect($sent)->sent->toBeTrue()->type->toBe('Housekeeping')->details->toBe('Two more towels, please.')
        ->and($request)->reservation_unit_id->toBe($unit->id)
        ->type->toBe(GuestRequestType::Housekeeping)
        ->source->toBe(GuestRequestSource::Concierge);
});

test('MakeGuestRequest passes on a refusal and writes nothing', function () {
    ['property' => $property, 'unit' => $unit] = conciergeSetup();
    $thread = stayToolsThread($property, $unit);
    $tool = app(MakeGuestRequest::class, ['thread' => $thread]);

    $refused = json_decode((string) $tool->handle(new ToolRequest(['type' => 'maintenance', 'details' => 'The shower is not draining.'])), true);

    expect($refused['sent'])->toBeFalse()
        ->and($refused['note'])->toContain(GuestRequestRefusal::StayNotInHouse->label())
        ->and(GuestRequest::query()->count())->toBe(0);
    expect(fn () => $tool->handle(new ToolRequest(['type' => 'refund', 'details' => 'Refund me.'])))->toThrow(ValidationException::class);
});
