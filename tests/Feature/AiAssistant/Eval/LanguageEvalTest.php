<?php

use App\Enums\AiTool;

require_once __DIR__.'/../../Support/AiEvalSetup.php';

/*
 * Opt-in live eval. Language: a booking and questions in Malay and Chinese. Rooms and rates are in aiEvalLobby(). Run with
 * `php artisan test --group=ai-eval`. See docs/35-ai-assistant/05-eval.md.
 */

afterAll(fn () => aiEvalReport('Language'));

test('the assistant handles', function (string $case, string $group, string $place, array $turns) {
    aiEvalRun($case, $group, $place, $turns);
})->with(aiEvalDataset([
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
]))->group('ai-eval')->repeat(aiEvalRepeat());
