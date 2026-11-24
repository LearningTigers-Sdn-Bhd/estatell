<?php

namespace App\Actions\AiAssistant;

use App\Ai\Agents\PropertyAssistant;
use App\Enums\AiOutcome;
use App\Models\AiThread;
use App\Services\AiAssistant\AiFixedReply;
use App\Services\AiAssistant\AiHandoverMessage;
use App\Services\AiAssistant\AiMonthlyUsage;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Vercel\Chat;

/**
 * Answer the newest message of a thread as a stream. At or over the monthly cap the model is not called: the guest gets
 * the handover message and the turn is logged as CapReached with no tokens. Otherwise the turn is logged once the stream
 * ends. See docs/34-ai-assistant/04-usage-and-cost.md#monthly-cap.
 */
class ReplyInAiThread
{
    public function __construct(
        private readonly AiMonthlyUsage $usage,
        private readonly AiHandoverMessage $handover,
        private readonly AiFixedReply $fixedReply,
        private readonly RecordAiTurn $recordTurn,
    ) {}

    public function __invoke(AiThread $thread, Chat $chat): StreamableAgentResponse
    {
        $property = $thread->property;

        if ($this->usage->capReached($property)) {
            ($this->recordTurn)($thread, 'none', new TextUsage, 0, outcome: AiOutcome::CapReached);

            return $this->fixedReply->stream($this->handover->forProperty($property))->usingProtocol($chat->protocol());
        }

        $startedAt = hrtime(true);

        return PropertyAssistant::make(thread: $thread)
            ->continue($thread->agent_conversation_id)
            ->stream($chat)
            ->then(fn (StreamedAgentResponse $response) => ($this->recordTurn)(
                $thread,
                $response->meta->model ?? (string) config('ai.providers.openai.models.text.default'),
                $response->usage,
                (int) ((hrtime(true) - $startedAt) / 1_000_000),
                $response->toolResults,
            ))
            ->usingProtocol($chat->protocol());
    }
}
