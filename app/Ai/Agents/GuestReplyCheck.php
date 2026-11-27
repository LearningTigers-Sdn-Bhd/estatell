<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Reads one assistant reply before the chat API sends it and says whether it is only a message to the guest. When the
 * reply also holds the writer's notes, plans or rules, it gives the message to the guest alone. It sees only the reply,
 * with no tools and no chat. See docs/35-ai-assistant/03-places-and-routes.md#reply-check.
 */
#[Timeout(20)]
class GuestReplyCheck implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
            You check one reply a hotel's chat assistant wrote to a guest, before it is sent.

            guest_only is true when the reply is only a message to the guest. It is false when the reply also holds the writer's own notes, such as rules it reminds itself of, steps it plans, questions it asks itself or a draft in quotes.

            When guest_only is false, put in reply only the message meant for the guest, as the guest should read it. Keep its words, links, prices, dates and numbers exactly. Add nothing. When guest_only is true, leave reply empty.
            TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'guest_only' => $schema->boolean()->required(),
            'reply' => $schema->string()->required(),
        ];
    }
}
