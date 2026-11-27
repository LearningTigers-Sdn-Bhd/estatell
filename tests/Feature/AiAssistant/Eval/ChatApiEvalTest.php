<?php

use App\Enums\AiTool;

require_once __DIR__.'/../../Support/AiEvalSetup.php';

/*
 * Opt-in live eval. Chat API: WhatsApp chats through n8n, including the real chat of 2026-11-27 word for word. Rooms and rates are in aiEvalLobby(). Run with
 * `php artisan test --group=ai-eval`. See docs/35-ai-assistant/05-eval.md.
 */

afterAll(fn () => aiEvalReport('Chat Api'));

test('the assistant handles', function (string $case, string $group, string $place, array $turns) {
    aiEvalRun($case, $group, $place, $turns);
})->with(aiEvalDataset([
    // Chat API: a WhatsApp chat through n8n, where the reply is plain text with no cards.
    // The real WhatsApp chat (2026-11-27) word for word: the reply to "5-8 dec" leaked the model's thoughts, and
    // "change to 6-9 dec" was handed over.
    'whatsapp chat replayed' => ['Chat API', 'api', [
        ['guest' => 'Hi-I-would-like-to-book-a-room', 'judge' => 'Asks for the dates and the number of guests (asking for adults is enough). The reply is only the message to the guest: no notes the assistant writes to itself (its rules, steps, plans or thoughts). Telling the guest how to reply, and a short line about the hotel, are fine.'],
        ['guest' => '5-8 dec', 'noTools' => [AiTool::HandOver], 'says' => ['2026'], 'judge' => 'Takes the dates as 5 to 8 December 2026 (any date format) and asks how many adults (it may ask about children). The reply is only the message to the guest: no notes the assistant writes to itself (its rules, steps, plans or thoughts). Telling the guest how to reply, and a short line about the hotel, are fine.'],
        ['guest' => '2 adult', 'tools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Lists numbered room options with prices for 5 to 8 December 2026. The reply is only the message to the guest: no notes the assistant writes to itself (its rules, steps, plans or thoughts). Telling the guest how to reply, and a short line about the hotel, are fine.'],
        ['guest' => '3', 'tools' => [AiTool::SendQuoteLink], 'says' => ['http'], 'judge' => 'Sends the booking link for the third option it listed.'],
        ['guest' => 'change to 6-9 dec', 'tools' => [AiTool::CheckRoomsAndPrices], 'noTools' => [AiTool::HandOver, AiTool::SendQuoteLink], 'judge' => 'Does not hand over. Shows the same room and plan as the link it sent, for 6 to 9 December 2026, as the current choice with its price, first, and asks the guest to keep it, then lists the other free options to pick from. Does not send a new link yet.'],
        ['guest' => 'ok', 'tools' => [AiTool::SendQuoteLink], 'says' => ['http'], 'judge' => 'Sends a new link for the same room and plan, 6 to 9 December 2026 for 2 adults.'],
    ]],
    'whatsapp changed dates when the plan is gone' => ['Chat API', 'api', [
        ['guest' => 'Family Suite, room only, 2 to 4 November 2026, 4 adults. Send me the link.', 'tools' => [AiTool::SendQuoteLink]],
        ['guest' => 'Can I change to 12-14 Nov instead?', 'tools' => [AiTool::CheckRoomsAndPrices], 'noTools' => [AiTool::HandOver, AiTool::SendQuoteLink], 'judge' => 'Does not hand over. Says the Family Suite (or any room for 4 adults) is not free on 12 to 14 November 2026, and offers other dates or staff help. Does not invent a free room or price.'],
    ]],
]))->group('ai-eval')->repeat(aiEvalRepeat());
