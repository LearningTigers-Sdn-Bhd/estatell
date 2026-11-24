<?php

namespace App\Data\AiAssistant;

use App\Data\Shared\BadgeData;
use App\Enums\AiOutcome;
use Spatie\LaravelData\Data;

/**
 * One outcome on the AI assistant Usage tab and how many turns ended that way.
 */
class AiUsageOutcomeData extends Data
{
    public function __construct(
        public BadgeData $outcome,
        public int $turns,
    ) {}

    public static function fromRow(AiOutcome $outcome, int $turns): self
    {
        return new self(BadgeData::fromAiOutcome($outcome), $turns);
    }
}
