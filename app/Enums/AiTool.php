<?php

namespace App\Enums;

/**
 * A tool the AI assistant may call. The value is the tool's class name in `app/Ai/Tools/`, which is also the name the
 * model sees. See docs/34-ai-assistant/02-agent-and-tools.md#tools.
 */
enum AiTool: string
{
    case PropertyFacts = 'PropertyFacts';
    case CheckRoomsAndPrices = 'CheckRoomsAndPrices';
    case SendQuoteLink = 'SendQuoteLink';
    case ReadMyStay = 'ReadMyStay';
    case MakeGuestRequest = 'MakeGuestRequest';
    case HandOver = 'HandOver';
    case DailyNumbers = 'DailyNumbers';
    case HowTo = 'HowTo';

    public function label(): string
    {
        return match ($this) {
            self::PropertyFacts => __('Property facts'),
            self::CheckRoomsAndPrices => __('Check rooms and prices'),
            self::SendQuoteLink => __('Send a quote link'),
            self::ReadMyStay => __('Read my stay'),
            self::MakeGuestRequest => __('Make a guest request'),
            self::HandOver => __('Hand over'),
            self::DailyNumbers => __('Daily numbers'),
            self::HowTo => __('How-to'),
        };
    }

    /**
     * The capability scope the tool needs. Null when the place scope is enough.
     */
    public function scope(): ?AiScope
    {
        return match ($this) {
            self::CheckRoomsAndPrices, self::SendQuoteLink => AiScope::RoomQuotes,
            self::ReadMyStay => AiScope::StayDetails,
            self::MakeGuestRequest => AiScope::GuestRequests,
            self::DailyNumbers => AiScope::DailyNumbers,
            self::HowTo => AiScope::HowTo,
            self::PropertyFacts, self::HandOver => null,
        };
    }
}
