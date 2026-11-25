<?php

namespace App\Enums;

/**
 * A place or a capability of the AI assistant. The platform admin allows each one for a property, and the hotel turns
 * it on. See docs/34-ai-assistant/01-overview.md#scopes.
 */
enum AiScope: string
{
    case Concierge = 'concierge';
    case BookingEngine = 'booking-engine';
    case Staff = 'staff';
    case Api = 'api';
    case RoomQuotes = 'room-quotes';
    case StayDetails = 'stay-details';
    case GuestRequests = 'guest-requests';
    case DailyNumbers = 'daily-numbers';
    case HowTo = 'how-to';

    public function label(): string
    {
        return match ($this) {
            self::Concierge => __('Concierge page'),
            self::BookingEngine => __('Booking website'),
            self::Staff => __('Staff (Ask Estatell)'),
            self::Api => __('Chat API (n8n)'),
            self::RoomQuotes => __('Room quotes'),
            self::StayDetails => __('Stay details'),
            self::GuestRequests => __('Guest requests'),
            self::DailyNumbers => __('Daily numbers'),
            self::HowTo => __('How-to'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Concierge => __('Guests chat from the lobby link and their stay link.'),
            self::BookingEngine => __('Visitors chat on the booking website.'),
            self::Staff => __('Staff ask how-to questions and today\'s numbers.'),
            self::Api => __('n8n or another tool sends guest messages, for example from WhatsApp.'),
            self::RoomQuotes => __('Checks free rooms and prices, and sends a link to book.'),
            self::StayDetails => __('Tells a guest their dates, room and balance due.'),
            self::GuestRequests => __('Sends housekeeping, maintenance and complaint requests for a guest.'),
            self::DailyNumbers => __('Arrivals, departures, in-house and unpaid counts for staff.'),
            self::HowTo => __('Answers from the Estatell user guide.'),
        };
    }

    public function kind(): AiScopeKind
    {
        return match ($this) {
            self::Concierge, self::BookingEngine, self::Staff, self::Api => AiScopeKind::Place,
            default => AiScopeKind::Capability,
        };
    }

    /**
     * The platform feature this scope needs on, besides the AI assistant itself.
     */
    public function requiredFeature(): ?PlatformFeature
    {
        return match ($this) {
            self::Concierge => PlatformFeature::Concierge,
            self::BookingEngine => PlatformFeature::BookingEngine,
            default => null,
        };
    }

    /**
     * The place scope a capability works in. A capability with no place works in every place that uses its tool.
     */
    public function requiredScope(): ?self
    {
        return match ($this) {
            self::StayDetails, self::GuestRequests => self::Concierge,
            self::DailyNumbers, self::HowTo => self::Staff,
            default => null,
        };
    }
}
