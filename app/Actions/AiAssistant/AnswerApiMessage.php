<?php

namespace App\Actions\AiAssistant;

use App\Ai\Agents\PropertyAssistant;
use App\Enums\AiOutcome;
use App\Models\AiThread;
use App\Services\AiAssistant\AiHandoverMessage;
use App\Services\AiAssistant\AiKeyProvider;
use App\Services\AiAssistant\AiReplyCheck;
use App\Services\AiAssistant\AiReplyGate;
use Laravel\Ai\Responses\Data\TextUsage;
use Throwable;

/**
 * Answer one chat API message in full, not as a stream, because n8n sends the reply back on the guest's channel. Like
 * the chat on the Concierge page: at or over the cap, or while the key is Failing, the guest gets the handover message
 * with no model call; a key OpenAI refuses becomes Failing and the guest gets the handover message. Any other failure is
 * logged as Failed and reported, and returns null so the controller answers 503. `escalated` is true when this turn
 * handed over to staff. Every reply passes `AiReplyCheck` before it is sent, as WhatsApp cannot take a message back: a
 * reply with the model's notes in it is sent cleaned, or, when it cannot be cleaned, the guest gets the handover message
 * and the thread is handed over. See docs/35-ai-assistant/03-places-and-routes.md#reply-check.
 */
class AnswerApiMessage
{
    public function __construct(
        private readonly AiReplyGate $gate,
        private readonly AiHandoverMessage $handover,
        private readonly RecordAiTurn $recordTurn,
        private readonly AiKeyProvider $keys,
        private readonly MarkAiKeyFailing $markKeyFailing,
        private readonly AiReplyCheck $replyCheck,
        private readonly ReplaceAiReplyText $replaceReply,
        private readonly EscalateAiThread $escalate,
    ) {}

    /**
     * @return array{reply: string, escalated: bool}|null
     */
    public function __invoke(AiThread $thread, string $message): ?array
    {
        $property = $thread->property;
        $blocked = $this->gate->blockedOutcome($property);

        if ($blocked !== null) {
            ($this->recordTurn)($thread, 'none', new TextUsage, 0, outcome: $blocked);

            return ['reply' => $this->handover->forProperty($property), 'escalated' => false];
        }

        $startedAt = hrtime(true);
        $model = (string) config('ai.providers.openai.models.text.default');

        try {
            $response = PropertyAssistant::make(thread: $thread)->continue($thread->agent_conversation_id)->prompt($message);
        } catch (Throwable $exception) {
            ($this->recordTurn)($thread, $model, new TextUsage, $this->elapsedMs($startedAt), outcome: AiOutcome::Failed);

            if ($this->keys->refusesKey($exception)) {
                ($this->markKeyFailing)($property);

                return ['reply' => $this->handover->forProperty($property), 'escalated' => false];
            }

            report($exception);

            return null;
        }

        $reply = $response->text;
        $usage = $response->usage;
        $heldBack = false;

        if (trim($reply) !== '') {
            try {
                $check = $this->replyCheck->check($property, $reply);
                $usage = $usage->add($check['usage']);
                $heldBack = $check['reply'] === null;
                $changed = $check['cleaned'];
                $reply = $check['reply'] ?? $this->handover->forProperty($property);
            } catch (Throwable $exception) {
                report($exception);
                $heldBack = true;
                $changed = true;
                $reply = $this->handover->forProperty($property);
            }

            if ($changed) {
                ($this->replaceReply)($thread, $reply);
            }

            if ($heldBack) {
                ($this->escalate)($thread, __('The assistant could not write a clear reply, so the guest got the handover message. Read the chat and reply to the guest.'));
            }
        }

        $turn = ($this->recordTurn)(
            $thread,
            $response->meta->model ?? $model,
            $usage,
            $this->elapsedMs($startedAt),
            $response->toolResults,
            $heldBack ? AiOutcome::Escalated : null,
        );

        return ['reply' => $reply, 'escalated' => $turn->outcome === AiOutcome::Escalated];
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
