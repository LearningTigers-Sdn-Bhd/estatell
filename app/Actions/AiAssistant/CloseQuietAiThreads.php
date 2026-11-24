<?php

namespace App\Actions\AiAssistant;

use App\Enums\AiThreadStatus;
use App\Models\AiThread;

/**
 * Close Open threads with no message for 7 days, so the next message starts a new thread. A handed-over thread stays
 * until staff resolve it in the AI chats inbox; it closes 7 quiet days after that. Returns how many closed. Running it
 * again finds nothing more. See docs/34-ai-assistant/04-usage-and-cost.md#data-model.
 */
class CloseQuietAiThreads
{
    public const int AFTER_DAYS = 7;

    public function __invoke(): int
    {
        return AiThread::query()
            ->where('status', AiThreadStatus::Open)
            ->where('last_message_at', '<=', now()->subDays(self::AFTER_DAYS))
            ->update(['status' => AiThreadStatus::Closed, 'updated_at' => now()]);
    }
}
