<?php

namespace App\Actions\AiAssistant;

use App\Enums\AiPlace;
use App\Enums\AiThreadStatus;
use App\Models\AiThread;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\AiAssistant\AiThreads;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\ConversationStore;

/**
 * Find the thread a new message belongs to, or start one, and mark that a message came in. A new thread gets its SDK
 * conversation here, so neither the browser nor the model picks a conversation id. Returns null when a lobby id is
 * already used by another thread (another property, another place, or closed): the browser then starts a new id. The
 * browser's language is kept on the thread the first time it is known.
 * See docs/35-ai-assistant/02-agent-and-tools.md#the-agent.
 */
class OpenAiThread
{
    public function __construct(
        private readonly AiThreads $threads,
        private readonly ConversationStore $conversations,
    ) {}

    public function __invoke(Property $property, AiPlace $place, ?ReservationUnit $stay = null, ?string $threadId = null, ?string $language = null): ?AiThread
    {
        $thread = $this->threads->current($property, $place, $stay, $threadId);

        if ($thread !== null) {
            $thread->update(['last_message_at' => now(), 'language' => $thread->language ?? $language]);

            return $thread;
        }

        $threadId = $stay === null ? $threadId : null;

        if ($threadId !== null && AiThread::query()->whereKey($threadId)->exists()) {
            return null;
        }

        return DB::transaction(fn (): AiThread => AiThread::query()->forceCreate([
            ...($threadId === null ? [] : ['id' => $threadId]),
            'property_id' => $property->id,
            'place' => $place,
            'status' => AiThreadStatus::Open,
            'agent_conversation_id' => $this->conversations->storeConversation(null, null, $place->label()),
            'reservation_unit_id' => $stay?->id,
            'language' => $language,
            'last_message_at' => now(),
        ]));
    }
}
