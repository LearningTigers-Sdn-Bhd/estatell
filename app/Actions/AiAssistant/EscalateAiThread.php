<?php

namespace App\Actions\AiAssistant;

use App\Actions\Webhooks\TriggerWebhook;
use App\Enums\AiThreadStatus;
use App\Enums\WebhookEvent;
use App\Models\AiThread;
use App\Models\UnitGuest;
use App\Models\User;
use App\Notifications\AiThreadEscalated;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Hand a thread over to staff: it becomes Escalated, the property's front desk staff get `AiThreadEscalated` and the
 * `ai.escalated` webhook is queued, both with the model's short summary and never the chat. The thread keeps answering
 * questions. Returns false without writing or sending when the thread is already handed over or closed.
 * See docs/34-ai-assistant/02-agent-and-tools.md#handover.
 */
class EscalateAiThread
{
    private const SUMMARY_LIMIT = 500;

    public function __construct(private TriggerWebhook $triggerWebhook) {}

    public function __invoke(AiThread $thread, string $summary): bool
    {
        if (! $thread->status->canTransitionTo(AiThreadStatus::Escalated)) {
            return false;
        }

        $thread->update(['status' => AiThreadStatus::Escalated, 'escalated_at' => now()]);

        $summary = Str::limit(trim($summary), self::SUMMARY_LIMIT);
        $thread->loadMissing(['property', 'reservationUnit.guests.guest']);
        $unit = $thread->reservationUnit;
        $primaryGuest = $unit?->guests->first(fn (UnitGuest $unitGuest): bool => $unitGuest->is_primary) ?? $unit?->guests->first();

        $message = new AiThreadEscalated(
            $thread->property->code,
            $thread->property->name,
            $thread->place->label(),
            $primaryGuest?->guest->full_name,
            $unit?->reservation_id,
            $summary,
        );

        Notification::send($this->staff($thread, $message), $message);
        ($this->triggerWebhook)(WebhookEvent::AiEscalated, $thread->property_id, AiThread::class, $thread->id, ['summary' => $summary]);

        return true;
    }

    /**
     * The active staff of the thread's property who may see the front desk.
     *
     * @return Collection<int, User>
     */
    private function staff(AiThread $thread, AiThreadEscalated $message): Collection
    {
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($thread->property_id);

        try {
            return User::query()
                ->where('property_id', $thread->property_id)
                ->where('is_admin', false)
                ->whereNull('deactivated_at')
                ->permission((string) $message->type->recipientPermission())
                ->get();
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }
}
