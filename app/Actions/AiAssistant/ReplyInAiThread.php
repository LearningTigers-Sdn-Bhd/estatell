<?php

namespace App\Actions\AiAssistant;

use App\Ai\Agents\PropertyAssistant;
use App\Enums\AiKeyStatus;
use App\Enums\AiOutcome;
use App\Models\AiThread;
use App\Services\AiAssistant\AiFixedReply;
use App\Services\AiAssistant\AiHandoverMessage;
use App\Services\AiAssistant\AiKeyProvider;
use App\Services\AiAssistant\AiMonthlyUsage;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Vercel\Chat;
use Throwable;

/**
 * Answer the newest message of a thread as a stream, on the hotel's own OpenAI key. At or over the monthly cap the model
 * is not called: the guest gets the handover message and the turn is logged as CapReached with no tokens. While the key
 * is Failing the same happens, logged as Failed. Otherwise the turn is logged once the stream ends. A stream that fails
 * (the provider is down, or the account is out of credit) is logged as Failed and reported, so it reaches the Laravel
 * log, and a key OpenAI refused becomes Failing. See docs/35-ai-assistant/04-usage-and-cost.md#monthly-cap and
 * docs/35-ai-assistant/01-overview.md#api-key.
 */
class ReplyInAiThread
{
    public function __construct(
        private readonly AiMonthlyUsage $usage,
        private readonly AiHandoverMessage $handover,
        private readonly AiFixedReply $fixedReply,
        private readonly RecordAiTurn $recordTurn,
        private readonly AiKeyProvider $keys,
        private readonly MarkAiKeyFailing $markKeyFailing,
    ) {}

    public function __invoke(AiThread $thread, Chat $chat): StreamableAgentResponse
    {
        $property = $thread->property;

        if ($this->usage->capReached($property)) {
            ($this->recordTurn)($thread, 'none', new TextUsage, 0, outcome: AiOutcome::CapReached);

            return $this->fixedReply->stream($this->handover->forProperty($property))->usingProtocol($chat->protocol());
        }

        if ($property->loadMissing('settings')->settings?->ai_key_status === AiKeyStatus::Failing) {
            ($this->recordTurn)($thread, 'none', new TextUsage, 0, outcome: AiOutcome::Failed);

            return $this->fixedReply->stream($this->handover->forProperty($property))->usingProtocol($chat->protocol());
        }

        $startedAt = hrtime(true);
        $model = (string) config('ai.providers.openai.models.text.default');

        return PropertyAssistant::make(thread: $thread)
            ->continue($thread->agent_conversation_id)
            ->stream($chat)
            ->then(fn (StreamedAgentResponse $response) => ($this->recordTurn)(
                $thread,
                $response->meta->model ?? $model,
                $response->usage,
                (int) ((hrtime(true) - $startedAt) / 1_000_000),
                $response->toolResults,
            ))
            ->catch(function (Throwable $exception) use ($thread, $property, $model, $startedAt): void {
                // The SDK reports other failures itself, but not an error the provider sent inside the stream.
                if ($exception instanceof StreamErrorException) {
                    report($exception);
                }

                if ($this->keys->refusesKey($exception)) {
                    ($this->markKeyFailing)($property);
                }

                ($this->recordTurn)($thread, $model, new TextUsage, (int) ((hrtime(true) - $startedAt) / 1_000_000), outcome: AiOutcome::Failed);
            })
            ->usingProtocol($chat->protocol());
    }
}
