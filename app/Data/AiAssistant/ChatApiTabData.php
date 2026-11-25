<?php

namespace App\Data\AiAssistant;

use App\Data\Shared\BadgeData;
use App\Models\AiConnection;
use App\Models\Property;
use App\Services\AiAssistant\AiKeyProvider;
use Spatie\LaravelData\Data;

/**
 * The Chat API tab on the admin property page: the connections, newest first, the endpoint n8n calls, and the status of
 * the hotel's OpenAI key (never the key). `keyBlocksReplies` is true while n8n gets no AI reply: no key, or a Failing
 * one. See docs/35-ai-assistant/03-places-and-routes.md#admin.
 */
class ChatApiTabData extends Data
{
    /**
     * @param  list<AiConnectionRowData>  $connections
     */
    public function __construct(
        public array $connections,
        public string $endpoint,
        public BadgeData $keyStatus,
        public bool $keyBlocksReplies,
    ) {}

    public static function forProperty(Property $property): self
    {
        $keyStatus = BadgeData::forAiKey(app(AiKeyProvider::class)->hasKey($property), $property->loadMissing('settings')->settings?->ai_key_status);

        return new self(
            connections: array_values($property->aiConnections()->latest('id')->get()->map(fn (AiConnection $connection): AiConnectionRowData => AiConnectionRowData::fromModel($connection))->all()),
            endpoint: route('api.ai.v1.messages.store'),
            keyStatus: $keyStatus,
            keyBlocksReplies: in_array($keyStatus->value, ['none', 'failing'], true),
        );
    }
}
