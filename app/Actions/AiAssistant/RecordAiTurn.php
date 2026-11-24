<?php

namespace App\Actions\AiAssistant;

use App\Actions\Concierge\AttachConciergeQuoteToAiTurn;
use App\Enums\AiOutcome;
use App\Enums\AiTool;
use App\Models\AiThread;
use App\Models\AiTurn;
use App\Services\AiAssistant\AiTurnCost;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolResult;

/**
 * Log one turn of a thread: its tokens, its cost, how it ended and the tools it called. No message text is kept here.
 * A quote link the turn sent is linked to it here, as the quote is made before the turn exists.
 * See docs/34-ai-assistant/04-usage-and-cost.md#data-model.
 */
class RecordAiTurn
{
    public function __construct(private readonly AiTurnCost $cost, private readonly AttachConciergeQuoteToAiTurn $attachQuote) {}

    /**
     * @param  iterable<int, ToolResult>  $toolResults
     */
    public function __invoke(AiThread $thread, string $model, TextUsage $usage, int $durationMs, iterable $toolResults = [], ?AiOutcome $outcome = null): AiTurn
    {
        $tools = [];
        $quoteIds = [];

        foreach ($toolResults as $result) {
            $tool = AiTool::tryFrom($result->name);

            if ($tool !== null) {
                $tools[] = ['tool' => $tool, 'succeeded' => $result->successful()];
            }

            if ($tool === AiTool::SendQuoteLink && $result->successful() && ($quoteId = $this->quoteId($result)) !== null) {
                $quoteIds[] = $quoteId;
            }
        }

        $outcome ??= match (true) {
            collect($tools)->contains('succeeded', false) => AiOutcome::Failed,
            collect($tools)->contains(fn (array $row): bool => $row['tool'] === AiTool::HandOver) => AiOutcome::Escalated,
            $quoteIds !== [] => AiOutcome::QuoteSent,
            default => AiOutcome::Answered,
        };

        return DB::transaction(function () use ($thread, $model, $usage, $durationMs, $tools, $quoteIds, $outcome): AiTurn {
            $turn = AiTurn::query()->create([
                'property_id' => $thread->property_id,
                'ai_thread_id' => $thread->id,
                'place' => $thread->place,
                'language' => $thread->language,
                'model' => $model,
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
                'cached_input_tokens' => $usage->cacheReadInputTokens ?? 0,
                'cost_micros' => $this->cost->micros($model, $usage),
                'outcome' => $outcome,
                'duration_ms' => $durationMs,
                'created_at' => now(),
            ]);

            if ($tools !== []) {
                $turn->tools()->createMany($tools);
            }

            foreach ($quoteIds as $quoteId) {
                ($this->attachQuote)($thread->property_id, $quoteId, $turn->id);
            }

            return $turn;
        });
    }

    /**
     * The quote a `SendQuoteLink` result made, or null when it sent none.
     */
    private function quoteId(ToolResult $result): ?int
    {
        $data = is_string($result->result) ? json_decode($result->result, true) : null;

        return is_array($data) && ($data['sent'] ?? false) === true && is_int($data['quote_id'] ?? null) ? $data['quote_id'] : null;
    }
}
