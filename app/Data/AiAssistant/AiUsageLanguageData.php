<?php

namespace App\Data\AiAssistant;

use Spatie\LaravelData\Data;

/**
 * One language on the AI assistant Usage tab: an ISO 639-1 code (null when not known) and its threads. The browser
 * shows the language name.
 */
class AiUsageLanguageData extends Data
{
    public function __construct(
        public ?string $language,
        public int $threads,
    ) {}
}
