<?php

namespace App\Actions\AiAssistant;

use App\Ai\Agents\PropertyAssistant;
use App\Enums\AiOutcome;
use App\Models\AiThread;
use App\Services\AiAssistant\AiHandoverMessage;
use App\Services\AiAssistant\AiKeyProvider;
use App\Services\AiAssistant\AiReplyGate;
use Laravel\Ai\Responses\Data\TextUsage;
use Throwable;

/**
 * Answer one chat API message in full, not as a stream, because n8n sends the reply back on the guest's channel. Like
 * the chat on the Concierge page: at or over the cap, or while the key is Failing, the guest gets the handover message
 * with no model call; a key OpenAI refuses becomes Failing and the guest gets the handover message. Any other failure is
 * logged as Failed and reported, and returns null so the controller answers 503. `escalated` is true when this turn
 * handed over to staff. See docs/35-ai-assistant/03-places-and-routes.md#request.
 */
class AnswerApiMessage
{
    public function __construct(
        private readonly AiReplyGate $gate,
        private readonly AiHandoverMessage $handover,
        private readonly RecordAiTurn $recordTurn,
        private readonly AiKeyProvider $keys,
        private readonly MarkAiKeyFailing $markKeyFailing,
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

        $turn = ($this->recordTurn)($thread, $response->meta->model ?? $model, $response->usage, $this->elapsedMs($startedAt), $response->toolResults);

        return ['reply' => $response->text, 'escalated' => $turn->outcome === AiOutcome::Escalated];
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
