<?php

namespace App\Services\AiAssistant;

use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;

/**
 * A reply the server writes itself, with no model call, shaped as a stream so the chat shows it like any other reply.
 * Used when the monthly cap is reached.
 */
class AiFixedReply
{
    public function stream(string $text): StreamableAgentResponse
    {
        $invocationId = (string) Str::uuid7();

        return new StreamableAgentResponse($invocationId, function () use ($text): \Generator {
            $messageId = (string) Str::uuid7();
            $now = time();

            yield new StreamStart((string) Str::uuid7(), 'estatell', 'none', $now);
            yield new TextStart((string) Str::uuid7(), $messageId, $now);
            yield new TextDelta((string) Str::uuid7(), $messageId, $text, $now);
            yield new TextEnd((string) Str::uuid7(), $messageId, $now);
            yield new StreamEnd((string) Str::uuid7(), 'stop', new TextUsage, $now);
        }, new Meta('estatell', 'none'));
    }
}
