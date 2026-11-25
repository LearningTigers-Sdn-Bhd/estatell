<?php

namespace App\Enums;

/**
 * Whether the hotel's own OpenAI key works. Null on the property means the key is not tested yet. A key goes Failing
 * when OpenAI refuses it (wrong key, or no credit), and back to Working only when the hotel tests it again.
 * See docs/35-ai-assistant/01-overview.md#api-key.
 */
enum AiKeyStatus: string
{
    case Working = 'working';
    case Failing = 'failing';

    public function label(): string
    {
        return match ($this) {
            self::Working => __('Working'),
            self::Failing => __('Not working'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Working => Tone::Success,
            self::Failing => Tone::Danger,
        };
    }
}
