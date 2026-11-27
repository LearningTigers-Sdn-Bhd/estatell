<?php

use App\Enums\AiTool;

require_once __DIR__.'/../../Support/AiEvalSetup.php';

/*
 * Opt-in live eval. Meaning and vague input: questions that sound like something else, and dates or parties that are not clear yet. Rooms and rates are in aiEvalLobby(). Run with
 * `php artisan test --group=ai-eval`. See docs/35-ai-assistant/05-eval.md.
 */

afterAll(fn () => aiEvalReport('Meaning'));

test('the assistant handles', function (string $case, string $group, string $place, array $turns) {
    aiEvalRun($case, $group, $place, $turns);
})->with(aiEvalDataset([
    // Meaning: questions that sound like something else.
    'room service is not a room search' => ['Meaning', 'lobby', [
        ['guest' => 'Do you have room service?', 'noTools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Treats room service as a hotel service: says it is not listed, points to the front desk, or hands over to the front desk. Does not search rooms or quote room prices.'],
    ]],
    'booking advice is policy' => ['Meaning', 'lobby', [
        ['guest' => 'What should I know before booking here?', 'noTools' => [AiTool::CheckRoomsAndPrices, AiTool::SendQuoteLink], 'judge' => 'Gives terms from the guide, such as check-in times, the deposit or house rules. Does not start a booking.'],
    ]],
    'cancellation is policy' => ['Meaning', 'lobby', [
        ['guest' => 'Is cancellation free?', 'noTools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'The guide has no cancellation policy. Says staff can confirm, or hands over to the front desk. Does not invent a policy, cancel anything or promise a refund.'],
    ]],
    'room price asks for dates first' => ['Meaning', 'lobby', [
        ['guest' => 'How much is a room?', 'noTools' => [AiTool::CheckRoomsAndPrices], 'never' => ['RM 100', 'RM 130'], 'judge' => 'Asks for the dates and the number of guests before giving any price. Asking for the number of adults counts as asking for the guests.'],
    ]],
    'hotel amenities are not room amenities' => ['Meaning', 'lobby', [
        ['guest' => 'What amenities does the hotel have?', 'judge' => 'Lists property amenities such as the pool, spa, restaurant or activities, not only things inside a room.'],
    ]],

    // Vague input: never guess a date or a party.
    'early november asks for the day' => ['Vague input', 'lobby', [
        ['guest' => 'Early November for 2 nights, 2 adults.', 'noTools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Asks which exact day the guest arrives. Does not pick a date itself.'],
    ]],
    'four people asks adults and children' => ['Vague input', 'lobby', [
        ['guest' => 'Room for 4 people from 2 to 4 November 2026.', 'noTools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Asks how many of the 4 are adults (and may ask about children) before checking prices. Does not assume all 4 are adults.'],
    ]],
    'a passed month is not refused as past' => ['Vague input', 'lobby', [
        ['guest' => 'Can I book a room on 28 August?', 'says' => ['2027'], 'judge' => 'Does not refuse because 28 August has passed. It writes 28 August 2027 in the reply, or says it can only check up to 6 months ahead.'],
    ]],
    'guest keeps missing the number' => ['Vague input', 'lobby', [
        ['guest' => 'I want a room from 2 to 4 November 2026.'],
        ['guest' => 'Just a small one for the family.', 'noTools' => [AiTool::SendQuoteLink]],
        ['guest' => 'Not too many la.', 'noTools' => [AiTool::SendQuoteLink], 'judge' => 'Asks again for the number of guests (asking for adults is enough), or offers to pass the guest to staff. Does not quote a price for a guessed number of guests.'],
    ]],
]))->group('ai-eval')->repeat(aiEvalRepeat());
