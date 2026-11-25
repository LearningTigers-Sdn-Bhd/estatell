<?php

namespace App\Ai\Tools;

use App\Models\Property;
use App\Services\AiAssistant\AssistantInstructions;
use App\Services\Concierge\ConciergeContent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * One part of what a guest may know about the property, in full. The instructions already hold most of it; the nearby
 * places are only here. See docs/35-ai-assistant/02-agent-and-tools.md#tools.
 */
class PropertyFacts implements Tool
{
    private const array TOPICS = ['property', 'amenities', 'rooms', 'policies', 'faqs', 'contact', 'nearby'];

    public function __construct(
        private readonly Property $property,
        private readonly AssistantInstructions $instructions,
        private readonly ConciergeContent $content,
    ) {}

    public function description(): Stringable|string
    {
        return 'Read one part of the property guide in full: property (address, times, getting here), amenities, rooms (room types: what each is like and who it fits), policies, faqs, contact, or nearby (places near the property).';
    }

    public function handle(Request $request): Stringable|string
    {
        $topic = $request->validate(['topic' => ['required', 'in:'.implode(',', self::TOPICS)]])['topic'];

        $facts = $topic === 'nearby'
            ? $this->content->places($this->property)->map->toArray()->all()
            : $this->instructions->knowledge($this->property)[$topic];

        return (string) json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['topic' => $schema->string()->enum(self::TOPICS)->required()];
    }
}
