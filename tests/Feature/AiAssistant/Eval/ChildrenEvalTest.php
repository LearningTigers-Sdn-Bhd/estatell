<?php

use App\Enums\AiTool;

require_once __DIR__.'/../../Support/AiEvalSetup.php';

/*
 * Opt-in live eval. Children: asking each age, quoting with them, and a family no room fits. Rooms and rates are in aiEvalLobby(). Run with
 * `php artisan test --group=ai-eval`. See docs/35-ai-assistant/05-eval.md.
 */

afterAll(fn () => aiEvalReport('Children'));

test('the assistant handles', function (string $case, string $group, string $place, array $turns) {
    aiEvalRun($case, $group, $place, $turns);
})->with(aiEvalDataset([
    // Children: ask each age, then quote with them. Rooms take up to 2 children.
    'babies on whatsapp' => ['Children', 'api', [
        ['guest' => 'hi, room available for 2-4 nov ?'],
        ['guest' => '3 adult, 2 baby', 'noTools' => [AiTool::HandOver, AiTool::CheckRoomsAndPrices], 'judge' => 'Asks the age of the two babies (or children). Does not hand over and gives no price yet.'],
        ['guest' => '1 and 2', 'tools' => [AiTool::CheckRoomsAndPrices], 'noTools' => [AiTool::HandOver], 'judge' => 'Lists room options with prices for 2 to 4 November 2026 for 3 adults and 2 children. Does not hand over.'],
    ]],
    'a baby in malay' => ['Children', 'lobby', [
        ['guest' => 'Bilik 2 hingga 4 November 2026, 2 dewasa dan 1 bayi.', 'noTools' => [AiTool::HandOver, AiTool::CheckRoomsAndPrices], 'judge' => 'Replies in Malay and asks how old the baby is. Does not hand over.'],
        ['guest' => '1 tahun.', 'tools' => [AiTool::CheckRoomsAndPrices], 'noTools' => [AiTool::HandOver], 'judge' => 'Replies in Malay with room options and prices for 2 adults and 1 child.'],
    ]],
    'a child in chinese' => ['Children', 'lobby', [
        ['guest' => '2026年11月2号到4号，两个大人一个小孩。', 'noTools' => [AiTool::HandOver, AiTool::CheckRoomsAndPrices], 'judge' => 'Replies in Chinese and asks how old the child is. Does not hand over.'],
    ]],
    'a family of five asks the party' => ['Children', 'lobby', [
        ['guest' => 'Room from 2 to 4 November 2026 for a family of 5.', 'noTools' => [AiTool::CheckRoomsAndPrices, AiTool::HandOver], 'judge' => 'Asks how many of the 5 are adults and how many are children (and may ask their ages). Does not assume all are adults.'],
    ]],
    'no room fits the family' => ['Children', 'lobby', [
        ['guest' => 'Room from 2 to 4 November 2026 for 4 adults and 3 kids aged 2, 5 and 8.', 'tools' => [AiTool::CheckRoomsAndPrices], 'noTools' => [AiTool::SendQuoteLink], 'judge' => 'Says no single room fits 4 adults and 3 children on those dates, and offers other dates or the front desk. Does not invent a room or price.'],
    ]],
]))->group('ai-eval')->repeat(aiEvalRepeat());
