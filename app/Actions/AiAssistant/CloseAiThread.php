<?php

namespace App\Actions\AiAssistant;

use App\Enums\AiThreadStatus;
use App\Models\AiThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Staff close a chat in the AI chats inbox, for example one an n8n flow looped. The guest's next message starts a new
 * thread with no history. Closing a handed-over chat also resolves its handover, with who and when. Returns false
 * without writing or logging when the thread is already closed. The activity log has no UUID subjects, so the entry is
 * on the property with the thread id. See docs/35-ai-assistant/03-places-and-routes.md#chat-handovers-inbox.
 */
class CloseAiThread
{
    public function __invoke(User $actor, AiThread $thread): bool
    {
        return DB::transaction(function () use ($actor, $thread): bool {
            $locked = AiThread::query()->where('property_id', $thread->property_id)->lockForUpdate()->findOrFail($thread->id);

            if (! $locked->status->canTransitionTo(AiThreadStatus::Closed)) {
                return false;
            }

            $resolve = $locked->status === AiThreadStatus::Escalated
                ? ['resolved_at' => now(), 'resolved_by_user_id' => $actor->id]
                : [];

            $locked->update(['status' => AiThreadStatus::Closed, ...$resolve]);

            activity()
                ->performedOn($locked->property()->firstOrFail())
                ->causedBy($actor)
                ->event('ai-assistant.thread-closed')
                ->withProperties(['property_id' => $locked->property_id, 'ai_thread_id' => $locked->id])
                ->log('AI chat closed');

            return true;
        });
    }
}
