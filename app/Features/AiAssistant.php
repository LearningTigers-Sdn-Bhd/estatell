<?php

namespace App\Features;

use App\Models\Property;
use Laravel\Pennant\Attributes\Name;

/**
 * Unlocks the AI assistant: a chat that answers guests and staff. Which places and capabilities it uses is set per
 * property in `property_ai_scopes`. See docs/34-ai-assistant/01-overview.md.
 */
#[Name('ai-assistant')]
class AiAssistant
{
    /**
     * Off until a platform admin turns it on.
     */
    public function resolve(Property $property): bool
    {
        return false;
    }
}
