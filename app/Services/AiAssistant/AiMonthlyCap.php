<?php

namespace App\Services\AiAssistant;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Converts the AI assistant's monthly cap between US dollars (what staff type) and micros (what is stored). OpenAI
 * bills in US dollars, so the cap and the usage log do too. See docs/34-ai-assistant/04-usage-and-cost.md#monthly-cap.
 */
class AiMonthlyCap
{
    private const int MICROS_PER_DOLLAR = 1_000_000;

    /**
     * "20.00" is 20000000. Blank is no cap.
     */
    public function toMicros(?string $dollars): ?int
    {
        if (blank($dollars)) {
            return null;
        }

        return BigDecimal::of($dollars)->multipliedBy(self::MICROS_PER_DOLLAR)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    /**
     * 20000000 is "20.00". No cap is null.
     */
    public function toDollars(?int $micros): ?string
    {
        if ($micros === null) {
            return null;
        }

        return (string) BigDecimal::of($micros)->dividedBy(self::MICROS_PER_DOLLAR, 2, RoundingMode::HalfUp);
    }
}
