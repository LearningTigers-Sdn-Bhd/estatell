<?php

namespace App\Actions\AiAssistant;

use App\Enums\AiThreadStatus;
use App\Models\AiThread;

/**
 * Hand a thread over to staff: it becomes Escalated, so staff see it. The thread keeps answering questions. Returns false
 * without writing when the thread is already handed over or closed. The staff notification and the webhook come with
 * the handover item. See docs/34-ai-assistant/02-agent-and-tools.md#handover.
 */
class EscalateAiThread
{
    public function __invoke(AiThread $thread): bool
    {
        if (! $thread->status->canTransitionTo(AiThreadStatus::Escalated)) {
            return false;
        }

        $thread->update(['status' => AiThreadStatus::Escalated, 'escalated_at' => now()]);

        return true;
    }
}
