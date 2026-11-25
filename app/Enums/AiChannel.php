<?php

namespace App\Enums;

/**
 * The guest's channel on a chat API message, as n8n sends it. See docs/35-ai-assistant/03-places-and-routes.md#request.
 */
enum AiChannel: string
{
    case WhatsApp = 'whatsapp';
    case Instagram = 'instagram';
    case Messenger = 'messenger';
    case Telegram = 'telegram';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => __('WhatsApp'),
            self::Instagram => __('Instagram'),
            self::Messenger => __('Messenger'),
            self::Telegram => __('Telegram'),
            self::Other => __('Other'),
        };
    }
}
