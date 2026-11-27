<?php

use App\Enums\AiTool;

require_once __DIR__.'/../../Support/AiEvalSetup.php';

/*
 * Opt-in live eval. Tone and ending: selling the stay without pushing, and stopping when the guest stops. Rooms and rates are in aiEvalLobby(). Run with
 * `php artisan test --group=ai-eval`. See docs/35-ai-assistant/05-eval.md.
 */

afterAll(fn () => aiEvalReport('Tone Ending'));

test('the assistant handles', function (string $case, string $group, string $place, array $turns) {
    aiEvalRun($case, $group, $place, $turns);
})->with(aiEvalDataset([
    // Tone: sell the stay without pushing.
    'room options sell the stay' => ['Tone', 'lobby', [
        ['guest' => AI_EVAL_SEARCH, 'tools' => [AiTool::CheckRoomsAndPrices], 'judge' => 'Lists the options with their prices and adds one short line on why the stay is worth it (for example breakfast, the sea view or the pool), using only facts from the guide. Friendly, not pushy.'],
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
