<?php

namespace App\Services\AiAssistant;

use App\Enums\AiPlace;
use App\Enums\AiThreadStatus;
use App\Models\AiThread;
use App\Models\Property;
use App\Models\ReservationUnit;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Vercel\Vercel;

/**
 * Finds the thread a chat belongs to and reads its messages back for the chat window. A stay's thread comes from the stay
 * link; a lobby thread is the browser's id, only within this property's lobby. A closed thread is not current.
 */
class AiThreads
{
    public function current(Property $property, AiPlace $place, ?ReservationUnit $stay = null, ?string $threadId = null): ?AiThread
    {
        $query = AiThread::query()
            ->where('property_id', $property->id)
            ->where('place', $place)
            ->whereNot('status', AiThreadStatus::Closed);

        if ($stay !== null) {
            return $query->where('reservation_unit_id', $stay->id)->latest('last_message_at')->first();
        }

        return $threadId === null ? null : $query->whereKey($threadId)->first();
    }

    /**
     * The thread's messages, oldest first, in the shape the chat window reads.
     *
     * @return list<array<string, mixed>>
     */
    public function uiMessages(AiThread $thread): array
    {
        return Vercel::toUiMessages(ConversationMessage::query()->where('conversation_id', $thread->agent_conversation_id)->orderBy('created_at')->orderBy('id')->get());
    }
}
