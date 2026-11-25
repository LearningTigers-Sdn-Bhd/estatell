<?php

namespace App\Actions\AiAssistant;

use App\Enums\AiChannel;
use App\Enums\AiPlace;
use App\Enums\AiThreadStatus;
use App\Models\AiConnection;
use App\Models\AiThread;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\ConversationStore;

/**
 * Find the chat API thread a new message belongs to, or start one, and mark that a message came in. n8n stores nothing:
 * it sends the channel's own chat id each time, and the thread is found by an HMAC of the property, the channel and that
 * id, never the id itself. The connection is not part of the hash, so a new token keeps the same chat.
 *
 * A thread continues while the guest's last message is under 24 hours old. After that a new thread starts: an Open one
 * is closed here, and a Handed over one stays as it is for staff. A short lock on the hash stops two quick messages
 * opening two threads. See docs/35-ai-assistant/03-places-and-routes.md#threads.
 */
class FindOrStartApiThread
{
    public function __construct(private readonly ConversationStore $conversations) {}

    public function __invoke(AiConnection $connection, AiChannel $channel, string $externalThread, ?string $guestName = null): AiThread
    {
        $hash = hash_hmac('sha256', $connection->property_id.'|'.$channel->value.'|'.$externalThread, (string) config('app.key'));

        return Cache::lock('ai-api-thread:'.$hash, 10)->block(10, fn (): AiThread => DB::transaction(function () use ($connection, $channel, $hash, $guestName): AiThread {
            $thread = $this->current($connection->property_id, $hash);
            $values = ['ai_connection_id' => $connection->id, 'last_message_at' => now()];

            if ($thread !== null) {
                $thread->update($values + ['guest_name' => $guestName ?? $thread->guest_name]);

                return $thread;
            }

            return AiThread::query()->forceCreate($values + [
                'property_id' => $connection->property_id,
                'place' => AiPlace::Api,
                'status' => AiThreadStatus::Open,
                'agent_conversation_id' => $this->conversations->storeConversation(null, null, AiPlace::Api->label()),
                'external_ref_hash' => $hash,
                'channel' => $channel,
                'guest_name' => $guestName,
            ]);
        }));
    }

    /**
     * The thread to continue, after closing this chat's Open threads that are 24 hours quiet.
     */
    private function current(int $propertyId, string $hash): ?AiThread
    {
        $threads = fn (): Builder => AiThread::query()
            ->where('property_id', $propertyId)
            ->where('place', AiPlace::Api)
            ->where('external_ref_hash', $hash);

        $threads()
            ->where('status', AiThreadStatus::Open)
            ->where('last_message_at', '<=', now()->subHours(CloseQuietAiThreads::API_AFTER_HOURS))
            ->update(['status' => AiThreadStatus::Closed, 'updated_at' => now()]);

        return $threads()
            ->where(fn (Builder $query) => $query
                ->where('status', AiThreadStatus::Open)
                ->orWhere(fn (Builder $handedOver) => $handedOver
                    ->where('status', AiThreadStatus::Escalated)
                    ->where('last_message_at', '>', now()->subHours(CloseQuietAiThreads::API_AFTER_HOURS))))
            ->latest('last_message_at')
            ->first();
    }
}
