<?php

namespace App\Enums;

/**
 * How one AI assistant turn ended. See docs/34-ai-assistant/04-usage-and-cost.md#data-model.
 */
enum AiOutcome: string
{
    case Answered = 'answered';
    case QuoteSent = 'quote-sent';
    case Booked = 'booked';
    case Escalated = 'escalated';
    case Refused = 'refused';
    case Failed = 'failed';
    case CapReached = 'cap-reached';

    public function label(): string
    {
        return match ($this) {
            self::Answered => __('Answered'),
            self::QuoteSent => __('Quote sent'),
            self::Booked => __('Booked'),
            self::Escalated => __('Handed over'),
            self::Refused => __('Refused'),
            self::Failed => __('Failed'),
            self::CapReached => __('Cap reached'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Answered => Tone::Neutral,
            self::QuoteSent => Tone::Info,
            self::Booked => Tone::Success,
            self::Escalated, self::CapReached => Tone::Warning,
            self::Refused, self::Failed => Tone::Danger,
        };
    }
}
