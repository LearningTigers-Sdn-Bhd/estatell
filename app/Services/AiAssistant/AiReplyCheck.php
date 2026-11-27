<?php

namespace App\Services\AiAssistant;

use App\Ai\Agents\GuestReplyCheck;
use App\Models\Property;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * Checks a chat API reply before it is sent, on the hotel's own key. A reply that is only for the guest passes as it is.
 * A reply with the writer's notes in it is cleaned once, and the clean text must pass a second check and keep every
 * link of the first. When it does not, the reply is null, and the caller sends the handover message instead.
 * See docs/35-ai-assistant/03-places-and-routes.md#reply-check.
 */
class AiReplyCheck
{
    public function __construct(private readonly AiKeyProvider $keys) {}

    /**
     * @return array{reply: ?string, cleaned: bool, usage: TextUsage}
     */
    public function check(Property $property, string $reply): array
    {
        $first = $this->ask($property, $reply);

        if ($first['guest_only']) {
            return ['reply' => $reply, 'cleaned' => false, 'usage' => $first['usage']];
        }

        $clean = trim($first['reply']);

        if ($clean === '' || ! $this->keepsLinks($reply, $clean)) {
            return ['reply' => null, 'cleaned' => true, 'usage' => $first['usage']];
        }

        $second = $this->ask($property, $clean);

        return [
            'reply' => $second['guest_only'] ? $clean : null,
            'cleaned' => true,
            'usage' => $first['usage']->add($second['usage']),
        ];
    }

    /**
     * @return array{guest_only: bool, reply: string, usage: TextUsage}
     */
    private function ask(Property $property, string $reply): array
    {
        $response = GuestReplyCheck::make()->prompt($reply, provider: $this->keys->for($property)->name());

        return ['guest_only' => (bool) $response['guest_only'], 'reply' => (string) $response['reply'], 'usage' => $response->usage];
    }

    private function keepsLinks(string $reply, string $clean): bool
    {
        preg_match_all('~https?://\S+~', $reply, $links);

        return collect($links[0])->every(fn (string $link): bool => str_contains($clean, rtrim($link, '.,;:!?)')));
    }
}
