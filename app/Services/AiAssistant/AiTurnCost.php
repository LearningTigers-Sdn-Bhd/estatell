<?php

namespace App\Services\AiAssistant;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * What one turn cost, in millionths of a US dollar, from its tokens and the model's price in `config/ai-pricing.php`.
 * A price per million tokens in dollars is the same number as micros per token. See
 * docs/34-ai-assistant/04-usage-and-cost.md#data-model.
 */
class AiTurnCost
{
    public function micros(string $model, TextUsage $usage): int
    {
        /** @var array{input: string, cached_input: string, output: string}|null $price */
        $price = config("ai-pricing.models.{$model}");

        if ($price === null) {
            return 0;
        }

        $cached = $usage->cacheReadInputTokens ?? 0;

        return BigDecimal::of($price['input'])->multipliedBy(max(0, $usage->inputTokens - $cached))
            ->plus(BigDecimal::of($price['cached_input'])->multipliedBy($cached))
            ->plus(BigDecimal::of($price['output'])->multipliedBy($usage->outputTokens))
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();
    }
}
