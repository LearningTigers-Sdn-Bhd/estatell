<?php

namespace App\Data\AiAssistant;

use App\Models\Property;
use App\Services\AiAssistant\AiHandoverMessage;
use Spatie\LaravelData\Data;

/**
 * The Handover tab of Settings › AI assistant: the property's own message and the built-in text used when it is empty.
 */
class AiHandoverSettingsData extends Data
{
    public function __construct(
        public ?string $handoverMessage,
        public string $defaultHandoverMessage,
    ) {}

    public static function forProperty(Property $property): self
    {
        return new self(
            handoverMessage: $property->settings?->ai_handover_message,
            defaultHandoverMessage: app(AiHandoverMessage::class)->default(),
        );
    }
}
