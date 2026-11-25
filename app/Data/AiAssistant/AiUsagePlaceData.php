<?php

namespace App\Data\AiAssistant;

use App\Enums\AiPlace;
use Spatie\LaravelData\Data;

/**
 * One place on the AI assistant Usage tab: its turns and estimated spend in US dollars.
 */
class AiUsagePlaceData extends Data
{
    public function __construct(
        public string $label,
        public int $turns,
        public string $spentUsd,
    ) {}

    public static function fromRow(AiPlace $place, int $turns, string $spentUsd): self
    {
        return new self($place->label(), $turns, $spentUsd);
    }
}
