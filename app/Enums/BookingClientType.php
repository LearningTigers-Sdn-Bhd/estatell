<?php

namespace App\Enums;

use App\Models\BookingClient;

/**
 * Who calls the booking engine API. The type decides which endpoints a new token gets, and `BookingClientReach`
 * uses it to decide which properties the client reaches. See docs/28-api-access/04-booking-engine-microsite.md.
 */
enum BookingClientType: string
{
    case Microsite = 'microsite';
    case ApiOnly = 'api-only';
    case Demo = 'demo';

    public function label(): string
    {
        return match ($this) {
            self::Microsite => __('Microsite'),
            self::ApiOnly => __('API only'),
            self::Demo => __('Demo'),
        };
    }

    /**
     * The abilities of a token of this type. Sanctum matches abilities exactly, so each group of endpoints has its own.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::Microsite => [BookingClient::ABILITY_LISTING, BookingClient::ABILITY_SITE, BookingClient::ABILITY_CONTENT, BookingClient::ABILITY_CHECKOUT],
            self::ApiOnly, self::Demo => [BookingClient::ABILITY_CONTENT, BookingClient::ABILITY_CHECKOUT],
        };
    }

    /**
     * Whether a hold needs the `X-Guest-IP` header. estatell runs the microsite, so it can trust and demand the value.
     * Another client's value is only an extra limit.
     */
    public function requiresGuestIp(): bool
    {
        return $this === self::Microsite;
    }

    /**
     * Whether a payment must name one of the property's approved return addresses. The microsite's own page is fixed.
     */
    public function requiresReturnAddress(): bool
    {
        return $this === self::ApiOnly;
    }
}
