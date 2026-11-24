<?php

use App\Enums\AiTool;

require_once __DIR__.'/../Support/AiEvalSetup.php';

/*
 * Opt-in live eval: multi-turn edge cases, adapted from the wastays concierge eval
 * (spec/fixtures/ai_concierge/conversations). The only free room is a Sea View Room from 2 to 4 November 2026. Run with
 * `php artisan test --group=ai-eval`. See docs/34-ai-assistant/05-eval.md.
 */

afterAll(fn () => aiEvalReport('Conversation'));

const AI_EVAL_SEARCH = 'I want a room from 2 to 4 November 2026 for 2 adults.';

test('the assistant handles', function (string $case, string $group, string $place, array $turns) {
    aiEvalRun($case, $group, $place, $turns);
})->with(aiEvalDataset([
    // Meaning: questions that sound like something else.
    'room service is not a room search' => ['Meaning', 'lobby', [
        ['guest' => 'Do you have room service?', 'noTools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Answers about room service as a hotel service (it is not listed, or ask the front desk). Does not search rooms or quote room prices.'],
    ]],
    'booking advice is policy' => ['Meaning', 'lobby', [
        ['guest' => 'What should I know before booking here?', 'noTools' => [AiTool::CheckRoomsAndPrices, AiTool::SendQuoteLink], 'judge' => 'Gives terms from the guide, such as check-in times, the deposit or house rules. Does not start a booking.'],
    ]],
    'cancellation is policy' => ['Meaning', 'lobby', [
        ['guest' => 'Is cancellation free?', 'noTools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Answers from the cancellation policy in the guide, or says staff can confirm. Does not cancel anything or promise a refund.'],
    ]],
    'room price asks for dates first' => ['Meaning', 'lobby', [
        ['guest' => 'How much is a room?', 'noTools' => [AiTool::CheckRoomsAndPrices], 'never' => ['RM 100', 'RM 130'], 'judge' => 'Asks for the dates and the number of guests before giving any price.'],
    ]],
    'hotel amenities are not room amenities' => ['Meaning', 'lobby', [
        ['guest' => 'What amenities does the hotel have?', 'judge' => 'Lists property amenities such as the pool, spa, restaurant or activities, not only things inside a room.'],
    ]],

    // Interruptions: a question in the middle of a booking.
    'question mid booking then resume' => ['Interruptions', 'lobby', [
        ['guest' => AI_EVAL_SEARCH, 'tools' => [AiTool::CheckRoomsAndPrices]],
        ['guest' => 'Wait, is parking free?', 'saysAny' => ['free']],
        ['guest' => 'Ok, send me the link for the room only option.', 'tools' => [AiTool::SendQuoteLink], 'judge' => 'Sends a quote link for 2 to 4 November 2026 for 2 adults on Room only, without asking for the dates again.'],
    ]],
    'two questions mid booking' => ['Interruptions', 'lobby', [
        ['guest' => AI_EVAL_SEARCH, 'tools' => [AiTool::CheckRoomsAndPrices]],
        ['guest' => 'What time is breakfast?', 'saysAny' => ['6 am', '6am', '6:00', '06:00']],
        ['guest' => 'And is there a pool?', 'judge' => 'Says yes and gives the pool hours.'],
        ['guest' => 'Ok, the one with breakfast please. Send the link.', 'tools' => [AiTool::SendQuoteLink], 'judge' => 'Sends a quote link for 2 to 4 November 2026 for 2 adults With breakfast.'],
    ]],
    'changed search searches again' => ['Interruptions', 'lobby', [
        ['guest' => AI_EVAL_SEARCH, 'tools' => [AiTool::CheckRoomsAndPrices]],
        ['guest' => 'Actually we are 3 adults.', 'tools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Checks again for 3 adults on the same dates, not the old search.'],
    ]],
    'a bare number picks a row' => ['Interruptions', 'lobby', [
        ['guest' => AI_EVAL_SEARCH, 'tools' => [AiTool::CheckRoomsAndPrices]],
        ['guest' => '1', 'judge' => 'Treats "1" as the first option it listed: it sends that quote link or confirms that option. It does not say it does not understand.'],
    ]],
    'a second yes does not send twice' => ['Interruptions', 'lobby', [
        ['guest' => 'Room from 2 to 4 November 2026 for 2 adults, with breakfast. Send me the link.', 'tools' => [AiTool::SendQuoteLink]],
        ['guest' => 'Is breakfast included in that one?', 'judge' => 'Says yes, the quote is With breakfast.'],
        ['guest' => 'Great, yes.', 'noTools' => [AiTool::SendQuoteLink], 'judge' => 'Does not send a second quote link. It may point to the link already sent.'],
    ]],

    // Vague input: never guess a date or a party.
    'early november asks for the day' => ['Vague input', 'lobby', [
        ['guest' => 'Early November for 2 nights, 2 adults.', 'noTools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Asks which exact day the guest arrives. Does not pick a date itself.'],
    ]],
    'four people asks adults and children' => ['Vague input', 'lobby', [
        ['guest' => 'Room for 4 people from 2 to 4 November 2026.', 'noTools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Asks how many are adults and how many are children before checking prices.'],
    ]],
    'a passed month is not refused as past' => ['Vague input', 'lobby', [
        ['guest' => 'Can I book a room on 28 August?', 'judge' => 'Does not refuse because 28 August has passed. It treats it as 28 August 2027, asks which year, or says it can only check up to 6 months ahead.'],
    ]],
    'guest keeps missing the number' => ['Vague input', 'lobby', [
        ['guest' => 'I want a room from 2 to 4 November 2026.'],
        ['guest' => 'Just a small one for the family.', 'noTools' => [AiTool::SendQuoteLink]],
        ['guest' => 'Not too many la.', 'noTools' => [AiTool::SendQuoteLink], 'judge' => 'Asks again for the number of adults and children, or offers to pass the guest to staff. Does not quote a price for a guessed number of guests.'],
    ]],

    // Language: reply in the guest's language.
    'booking in malay' => ['Language', 'lobby', [
        ['guest' => 'Boleh saya tempah bilik?', 'judge' => 'Replies in Malay and asks for the dates and the number of guests.'],
        ['guest' => '2 hingga 4 November 2026, 2 dewasa.', 'tools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Replies in Malay with the room options and their prices.'],
    ]],
    'booking in chinese' => ['Language', 'lobby', [
        ['guest' => '我想订房', 'judge' => 'Replies in Chinese and asks for the dates and the number of guests.'],
        ['guest' => '2026年11月2号入住，住两晚，两位大人', 'tools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Replies in Chinese with the room options and their prices for 2 to 4 November.'],
    ]],
    'switch to malay mid chat' => ['Language', 'lobby', [
        ['guest' => 'Hi, what time is check-in?', 'saysAny' => ['3 pm', '3pm', '3:00', '15:00']],
        ['guest' => 'Boleh saya simpan beg awal?', 'judge' => 'Replies in Malay. Says bags can be left at the front desk.'],
        ['guest' => 'Ok terima kasih', 'judge' => 'Replies in Malay.'],
    ]],

    // Ending: stop when the guest stops.
    'forget the room' => ['Ending', 'lobby', [
        ['guest' => AI_EVAL_SEARCH, 'tools' => [AiTool::CheckRoomsAndPrices]],
        ['guest' => 'Forget the room.'],
        ['guest' => 'What time is breakfast?', 'noTools' => [AiTool::SendQuoteLink], 'judge' => 'Answers the breakfast time and does not bring back the dropped room search.'],
    ]],
    'thanks ends politely' => ['Ending', 'lobby', [
        ['guest' => 'What time is check-in?'],
        ['guest' => "Thanks, that's all.", 'noTools' => [AiTool::CheckRoomsAndPrices, AiTool::SendQuoteLink, AiTool::HandOver], 'judge' => 'Ends politely and briefly. Does not push a booking.'],
    ]],
    'question on the way out' => ['Ending', 'lobby', [
        ['guest' => AI_EVAL_SEARCH, 'tools' => [AiTool::CheckRoomsAndPrices]],
        ['guest' => 'Thanks. Before I go, what time does breakfast start?', 'saysAny' => ['6 am', '6am', '6:00', '06:00']],
        ['guest' => 'Ok, send me the link for the breakfast option.', 'tools' => [AiTool::SendQuoteLink]],
    ]],
]))->group('ai-eval')->repeat(3);
