<?php

namespace App\Enums;

/**
 * Where an AI assistant thread runs. The place decides which tools the agent gets and which scope must be on.
 * See docs/34-ai-assistant/03-places-and-routes.md.
 */
enum AiPlace: string
{
    case ConciergeLobby = 'concierge-lobby';
    case ConciergeStay = 'concierge-stay';
    case BookingEngine = 'booking-engine';
    case Staff = 'staff';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::ConciergeLobby => __('Concierge lobby'),
            self::ConciergeStay => __('Concierge stay'),
            self::BookingEngine => __('Booking website'),
            self::Staff => __('Staff'),
            self::Api => __('Chat API'),
        };
    }

    /**
     * The place scope that must be on for a thread here.
     */
    public function scope(): AiScope
    {
        return match ($this) {
            self::ConciergeLobby, self::ConciergeStay => AiScope::Concierge,
            self::BookingEngine => AiScope::BookingEngine,
            self::Staff => AiScope::Staff,
            self::Api => AiScope::Api,
        };
    }

    /**
     * The tools an agent may get in this place, before the capability scopes are checked.
     *
     * @return list<AiTool>
     */
    public function tools(): array
    {
        return match ($this) {
            self::ConciergeLobby, self::BookingEngine, self::Api => [AiTool::PropertyFacts, AiTool::CheckRoomsAndPrices, AiTool::SendQuoteLink, AiTool::HandOver],
            self::ConciergeStay => [AiTool::PropertyFacts, AiTool::CheckRoomsAndPrices, AiTool::SendQuoteLink, AiTool::ReadMyStay, AiTool::MakeGuestRequest, AiTool::HandOver],
            self::Staff => [AiTool::DailyNumbers, AiTool::HowTo, AiTool::HandOver],
        };
    }
}
