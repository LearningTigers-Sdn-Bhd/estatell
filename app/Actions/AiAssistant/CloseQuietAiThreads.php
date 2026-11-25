<?php

namespace App\Actions\AiAssistant;

use App\Enums\AiPlace;
use App\Enums\AiThreadStatus;
use App\Models\AiThread;
use Illuminate\Database\Eloquent\Builder;

/**
 * Close Open threads with no message for 7 days, so the next message starts a new thread. A handed-over thread stays
 * until staff resolve it in the AI chats inbox; it closes 7 quiet days after that. A chat API thread closes after 24 quiet hours instead. Returns how many closed. Running it
 * again finds nothing more. See docs/35-ai-assistant/04-usage-and-cost.md#data-model.
 */
class CloseQuietAiThreads
{
    public const int AFTER_DAYS = 7;

    /** A chat API thread ends this many hours after the guest's last message, as WhatsApp's reply window does. */
    public const int API_AFTER_HOURS = 24;

    public function __invoke(): int
    {
        return AiThread::query()
            ->where('status', AiThreadStatus::Open)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $api) => $api->where('place', AiPlace::Api)->where('last_message_at', '<=', now()->subHours(self::API_AFTER_HOURS)))
                ->orWhere(fn (Builder $other) => $other->where('place', '!=', AiPlace::Api)->where('last_message_at', '<=', now()->subDays(self::AFTER_DAYS))))
            ->update(['status' => AiThreadStatus::Closed, 'updated_at' => now()]);
    }
}
