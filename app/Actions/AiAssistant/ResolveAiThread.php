<?php

namespace App\Actions\AiAssistant;

use App\Enums\AiThreadStatus;
use App\Models\AiThread;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Staff mark a handover handled in the AI chats inbox: the thread goes from Escalated back to Open, with who resolved it
 * and when, so the guest keeps the same chat and a new handover alerts staff again. Returns false without writing or
 * logging when the thread is not handed over (already resolved, or closed). The activity log has no UUID subjects, so
 * the entry is on the property with the thread id. See docs/35-ai-assistant/03-places-and-routes.md#ai-chats-inbox.
 */
class ResolveAiThread
{
    public function __invoke(User $actor, AiThread $thread): bool
    {
        return DB::transaction(function () use ($actor, $thread): bool {
            $locked = AiThread::query()->where('property_id', $thread->property_id)->lockForUpdate()->findOrFail($thread->id);

            if ($locked->status !== AiThreadStatus::Escalated || ! $locked->status->canTransitionTo(AiThreadStatus::Open)) {
                return false;
            }

            $locked->update(['status' => AiThreadStatus::Open, 'resolved_at' => now(), 'resolved_by_user_id' => $actor->id]);

            activity()
                ->performedOn($locked->property()->firstOrFail())
                ->causedBy($actor)
                ->event('ai-assistant.thread-resolved')
                ->withProperties(['property_id' => $locked->property_id, 'ai_thread_id' => $locked->id])
                ->log('AI chat handover resolved');

            return true;
        });
    }
}
