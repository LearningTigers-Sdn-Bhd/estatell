<?php

namespace App\Actions\AiAssistant;

use App\Models\AiThread;
use Illuminate\Support\Facades\DB;

/**
 * Delete AI chat message text older than 90 days, and the handover summaries written that long ago. The usage log
 * (`ai_turns`, `ai_turn_tools`) keeps no text, so it stays. Returns how many messages were deleted. Running it again
 * finds nothing more. See docs/34-ai-assistant/01-overview.md#personal-data.
 */
class PurgeAiMessageText
{
    public const int AFTER_DAYS = 90;

    public function __invoke(): int
    {
        $cutoff = now()->subDays(self::AFTER_DAYS);

        return DB::transaction(function () use ($cutoff): int {
            AiThread::query()
                ->whereNotNull('handover_summary')
                ->where('escalated_at', '<=', $cutoff)
                ->update(['handover_summary' => null]);

            return DB::table((string) config('ai.conversations.tables.messages', 'agent_conversation_messages'))
                ->where('created_at', '<=', $cutoff)
                ->delete();
        });
    }
}
