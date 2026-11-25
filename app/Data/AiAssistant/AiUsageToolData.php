<?php

namespace App\Data\AiAssistant;

use App\Enums\AiTool;
use Spatie\LaravelData\Data;

/**
 * One tool on the AI assistant Usage tab: how often it ran and how often it failed.
 */
class AiUsageToolData extends Data
{
    public function __construct(
        public string $label,
        public int $runs,
        public int $failures,
    ) {}

    public static function fromRow(AiTool $tool, int $runs, int $failures): self
    {
        return new self($tool->label(), $runs, $failures);
    }
}
