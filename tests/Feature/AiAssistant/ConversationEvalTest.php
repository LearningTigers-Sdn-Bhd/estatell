<?php

use App\Enums\AiTool;

require_once __DIR__.'/../Support/AiEvalSetup.php';

/*
 * Opt-in live eval: multi-turn edge cases, adapted from the wastays concierge eval
 * (spec/fixtures/ai_concierge/conversations). Rooms and rates are in aiEvalLobby(): Garden Room, Sea View Room and Family
 * Suite, free 2 to 4 November 2026, and Garden and Sea View also 12 to 15 November. `api` cases use the same rooms
 * through the chat API, as a WhatsApp chat from n8n. Run with
 * `php artisan test --group=ai-eval`. See docs/35-ai-assistant/05-eval.md.
 */

afterAll(fn () => aiEvalReport('Conversation'));

const AI_EVAL_SEARCH = 'I want a room from 2 to 4 November 2026 for 2 adults.';

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

    // Interruptions: a question in the middle of a booking.
    'question mid booking then resume' => ['Interruptions', 'lobby', [
        ['guest' => AI_EVAL_SEARCH, 'tools' => [AiTool::CheckRoomsAndPrices]],
        ['guest' => 'Wait, is parking free?', 'saysAny' => ['free']],
        ['guest' => 'Ok, send me the link for the Sea View Room, room only.', 'tools' => [AiTool::SendQuoteLink], 'judge' => 'Sends a quote link for the Sea View Room, Room only, 2 to 4 November 2026 for 2 adults, without asking for the dates again.'],
    ]],
    'two questions mid booking' => ['Interruptions', 'lobby', [
        ['guest' => AI_EVAL_SEARCH, 'tools' => [AiTool::CheckRoomsAndPrices]],
        ['guest' => 'What time is breakfast?', 'saysAny' => ['6 am', '6am', '6:00', '06:00']],
        ['guest' => 'And is there a pool?', 'judge' => 'Says yes and gives the pool hours.'],
        ['guest' => 'Ok, the Sea View Room with breakfast please. Send the link.', 'tools' => [AiTool::SendQuoteLink], 'judge' => 'Sends a quote link for the Sea View Room With breakfast, 2 to 4 November 2026 for 2 adults.'],
    ]],
    'changed search searches again' => ['Interruptions', 'lobby', [
        ['guest' => AI_EVAL_SEARCH, 'tools' => [AiTool::CheckRoomsAndPrices]],
        ['guest' => 'Actually we are 3 adults.', 'tools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Checks again for 3 adults on the same dates, not the old search.'],
    ]],
    'a bare number picks a row' => ['Interruptions', 'lobby', [
        ['guest' => AI_EVAL_SEARCH, 'tools' => [AiTool::CheckRoomsAndPrices]],
        ['guest' => '1', 'judge' => 'Treats "1" as the first numbered option it listed (a room and plan): it sends that quote link or confirms that option. It does not ask the guest to choose again.'],
    ]],
    'a second yes does not send twice' => ['Interruptions', 'lobby', [
        ['guest' => 'Sea View Room from 2 to 4 November 2026 for 2 adults, with breakfast. Send me the link.', 'tools' => [AiTool::SendQuoteLink]],
        ['guest' => 'Is breakfast included in that one?', 'judge' => 'Says yes, the quote is With breakfast.'],
        ['guest' => 'Great, yes.', 'noTools' => [AiTool::SendQuoteLink], 'judge' => 'Does not send a second quote link. It may point to the link already sent.'],
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

    // Language: reply in the guest's language.
    'booking in malay' => ['Language', 'lobby', [
        ['guest' => 'Boleh saya tempah bilik?', 'judge' => 'Replies in Malay and asks for the dates and the number of guests. Asking for the number of adults counts.'],
        ['guest' => '2 hingga 4 November 2026, 2 dewasa.', 'tools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Replies in Malay with the room options and their prices.'],
    ]],
    'booking in chinese' => ['Language', 'lobby', [
        ['guest' => '我想订房', 'judge' => 'Replies in Chinese and asks for the dates and the number of guests. Asking for the number of adults counts.'],
        ['guest' => '2026年11月2号入住，住两晚，两位大人', 'tools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Replies in Chinese with the room options and their prices for 2 to 4 November.'],
    ]],
    'switch to malay mid chat' => ['Language', 'lobby', [
        ['guest' => 'Hi, what time is check-in?', 'saysAny' => ['3 pm', '3pm', '3:00', '15:00']],
        ['guest' => 'Boleh saya simpan beg awal?', 'judge' => 'Replies in Malay. Says bags can be left at the front desk.'],
        ['guest' => 'Ok terima kasih', 'judge' => 'Replies in Malay.'],
    ]],

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

    // Tone: sell the stay without pushing.
    'room options sell the stay' => ['Tone', 'lobby', [
        ['guest' => AI_EVAL_SEARCH, 'tools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Lists the options with their prices and adds one short line on why the stay is worth it (for example breakfast, the sea view or the pool), using only facts from the guide. Friendly, not pushy.'],
    ]],

    // Changing a quote: a quote link is not a booking, so new dates get a fresh check, not a handover.
    'changed dates keep the plan' => ['Changing a quote', 'lobby', [
        ['guest' => 'Sea View Room, room only, from 2 to 4 November 2026 for 2 adults. Send me the link.', 'tools' => [AiTool::SendQuoteLink]],
        ['guest' => 'Change to 12-14 Nov.', 'tools' => [AiTool::CheckRoomsAndPrices], 'noTools' => [AiTool::HandOver, AiTool::SendQuoteLink], 'judge' => 'Does not hand over. Offers the same Sea View Room, Room only for 12 to 14 November 2026 with its price, first or clearly marked as the choice of the guest, also lists the other free options, and asks before sending a new link. A numbered list with a question about one option counts, as the guest can reply with another number.'],
        ['guest' => 'Yes, keep that one.', 'tools' => [AiTool::SendQuoteLink], 'judge' => 'Sends a new link for the Sea View Room, Room only, 12 to 14 November 2026 for 2 adults.'],
    ]],

    // Chat API: a WhatsApp chat through n8n, where the reply is plain text with no cards.
    // The real WhatsApp chat (2026-11-27) word for word: the reply to "5-8 dec" leaked the model's thoughts, and
    // "change to 6-9 dec" was handed over.
    'whatsapp chat replayed' => ['Chat API', 'api', [
        ['guest' => 'Hi-I-would-like-to-book-a-room', 'judge' => 'Asks for the dates and the number of guests (asking for adults is enough). The reply is only the message to the guest: no notes about rules, steps, plans or what the assistant should do.'],
        ['guest' => '5-8 dec', 'noTools' => [AiTool::HandOver], 'says' => ['2026'], 'judge' => 'Takes the dates as 5 to 8 December 2026 (any date format) and asks how many adults (it may ask about children). The reply is only the message to the guest: no notes about rules, steps, plans or what the assistant should do.'],
        ['guest' => '2 adult', 'tools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Lists numbered room options with prices for 5 to 8 December 2026. The reply is only the message to the guest: no notes about rules, steps, plans or what the assistant should do.'],
        ['guest' => '3', 'tools' => [AiTool::SendQuoteLink], 'says' => ['http'], 'judge' => 'Sends the booking link for the third option it listed.'],
        ['guest' => 'change to 6-9 dec', 'tools' => [AiTool::CheckRoomsAndPrices], 'noTools' => [AiTool::HandOver, AiTool::SendQuoteLink], 'judge' => 'Does not hand over. Offers the same room and plan as the link it sent, for 6 to 9 December 2026 with its price, first or clearly marked as the choice of the guest, also lists the other free options, and asks before sending a new link. A numbered list with a question about one option counts, as the guest can reply with another number.'],
        ['guest' => 'ok', 'tools' => [AiTool::SendQuoteLink], 'says' => ['http'], 'judge' => 'Sends a new link for the same room and plan, 6 to 9 December 2026 for 2 adults.'],
    ]],
    'whatsapp changed dates when the plan is gone' => ['Chat API', 'api', [
        ['guest' => 'Family Suite, room only, 2 to 4 November 2026, 4 adults. Send me the link.', 'tools' => [AiTool::SendQuoteLink]],
        ['guest' => 'Can I change to 12-14 Nov instead?', 'tools' => [AiTool::CheckRoomsAndPrices], 'noTools' => [AiTool::HandOver, AiTool::SendQuoteLink], 'judge' => 'Does not hand over. Says the Family Suite (or any room for 4 adults) is not free on 12 to 14 November 2026, and offers other dates or staff help. Does not invent a free room or price.'],
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
        ['guest' => 'Ok, send me the link for the Sea View Room with breakfast.', 'tools' => [AiTool::SendQuoteLink]],
    ]],
]))->group('ai-eval')->repeat(aiEvalRepeat());
