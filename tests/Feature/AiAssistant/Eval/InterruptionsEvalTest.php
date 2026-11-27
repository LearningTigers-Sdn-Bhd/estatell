<?php

use App\Enums\AiTool;

require_once __DIR__.'/../../Support/AiEvalSetup.php';

/*
 * Opt-in live eval. Interruptions: a question or a change in the middle of a booking. Rooms and rates are in aiEvalLobby(). Run with
 * `php artisan test --group=ai-eval`. See docs/35-ai-assistant/05-eval.md.
 */

afterAll(fn () => aiEvalReport('Interruptions'));

test('the assistant handles', function (string $case, string $group, string $place, array $turns) {
    aiEvalRun($case, $group, $place, $turns);
})->with(aiEvalDataset([
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
]))->group('ai-eval')->repeat(aiEvalRepeat());
