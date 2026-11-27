<?php

use App\Enums\AiTool;

require_once __DIR__.'/../../Support/AiEvalSetup.php';

/*
 * Opt-in live eval. Quote flow: gathering the stay, offering rooms, sending a quote link and changing it. Rooms and rates are in aiEvalLobby(). Run with
 * `php artisan test --group=ai-eval`. See docs/35-ai-assistant/05-eval.md.
 */

afterAll(fn () => aiEvalReport('Quote Flow'));

test('the assistant handles', function (string $case, string $group, string $place, array $turns) {
    aiEvalRun($case, $group, $place, $turns);
})->with(aiEvalDataset([
    // Booking flow: gather the dates, the nights and the guests before a search, then book the room the guest picks.
    'arrival date only asks for the rest' => ['Booking flow', 'lobby', [
        ['guest' => 'I want to book on 12 November.', 'noTools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Asks for the check-out date or the number of nights, and the number of guests (asking for adults is enough). Does not quote a price yet.'],
    ]],
    'booking step by step' => ['Booking flow', 'lobby', [
        ['guest' => 'I want to book on 12 November.', 'noTools' => [AiTool::CheckRoomsAndPrices]],
        ['guest' => '2 nights.', 'noTools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Asks for the number of guests or adults. Keeps 12 November as the arrival and does not ask for the date again.'],
        ['guest' => '2 adults.', 'tools' => [AiTool::CheckRoomsAndPrices], 'says' => ['Garden', 'Sea View'], 'judge' => 'Lists the Garden Room and the Sea View Room for 12 to 14 November 2026 with their prices. Does not offer the Family Suite.'],
    ]],
    'never assumes one night' => ['Booking flow', 'lobby', [
        ['guest' => 'Room on 12 November for 2 adults.', 'noTools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Asks how many nights or the check-out date. Does not assume one night.'],
    ]],
    'full booking path' => ['Booking flow', 'lobby', [
        ['guest' => "Hi, I'd like a room from 12 to 14 November.", 'noTools' => [AiTool::CheckRoomsAndPrices]],
        ['guest' => '2 adults.', 'tools' => [AiTool::CheckRoomsAndPrices]],
        ['guest' => 'The garden one.', 'noTools' => [AiTool::SendQuoteLink], 'judge' => 'Asks whether the guest wants Room only or With breakfast for the Garden Room. Does not send a link yet.'],
        ['guest' => 'With breakfast.', 'tools' => [AiTool::SendQuoteLink], 'says' => ['Garden'], 'judge' => 'Says the booking link for the Garden Room With breakfast was sent or is ready. It need not repeat the dates or the guests.'],
    ]],
    'pick a room by name' => ['Booking flow', 'lobby', [
        ['guest' => AI_EVAL_SEARCH, 'tools' => [AiTool::CheckRoomsAndPrices]],
        ['guest' => 'Sea view with breakfast please.', 'tools' => [AiTool::SendQuoteLink], 'says' => ['Sea View'], 'judge' => 'Sends the link for the Sea View Room With breakfast, not another room or plan.'],
    ]],
    'a big group gets the room that fits' => ['Booking flow', 'lobby', [
        ['guest' => 'Room from 2 to 4 November 2026 for 4 adults.', 'tools' => [AiTool::CheckRoomsAndPrices], 'says' => ['Family Suite'], 'judge' => 'Offers only the Family Suite with its prices. Does not offer the Garden Room or the Sea View Room.'],
    ]],
    'a sold-out room type' => ['Booking flow', 'lobby', [
        ['guest' => 'Family Suite from 12 to 14 November 2026 for 4 adults.', 'tools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Says the Family Suite, or any room for 4 adults, is not free on those dates. Offers other dates, a smaller room choice or staff help. Does not invent a free room or a price.'],
    ]],
    'compare room types' => ['Booking flow', 'lobby', [
        ['guest' => 'What is the difference between the Garden Room and the Sea View Room?', 'saysAny' => ['patio', 'balcony'], 'judge' => 'Compares the two rooms using their descriptions in the guide (bed, size, the garden patio, the sea-facing balcony). Invents nothing that the descriptions do not say.'],
    ]],

    // Changing a quote: a quote link is not a booking, so new dates get a fresh check, not a handover.
    'changed dates keep the plan' => ['Changing a quote', 'lobby', [
        ['guest' => 'Sea View Room, room only, from 2 to 4 November 2026 for 2 adults. Send me the link.', 'tools' => [AiTool::SendQuoteLink]],
        ['guest' => 'Change to 12-14 Nov.', 'tools' => [AiTool::CheckRoomsAndPrices], 'noTools' => [AiTool::HandOver, AiTool::SendQuoteLink], 'judge' => 'Does not hand over. Shows the same Sea View Room, Room only for 12 to 14 November 2026 as the current choice with its price, first, and asks the guest to keep it, then lists the other free options to pick from. Does not send a new link yet.'],
        ['guest' => 'Yes, keep that one.', 'tools' => [AiTool::SendQuoteLink], 'judge' => 'Sends a new link for the Sea View Room, Room only, 12 to 14 November 2026 for 2 adults.'],
    ]],
]))->group('ai-eval')->repeat(aiEvalRepeat());
