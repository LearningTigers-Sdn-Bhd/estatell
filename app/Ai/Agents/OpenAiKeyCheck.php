<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The smallest call that proves a hotel's OpenAI key works: a one-word reply. OpenAI has no free call that also checks
 * the account has credit, so this costs the hotel a fraction of a cent. See docs/34-ai-assistant/01-overview.md#api-key.
 */
#[MaxTokens(16)]
#[Timeout(15)]
class OpenAiKeyCheck implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'Reply with the word OK.';
    }
}
