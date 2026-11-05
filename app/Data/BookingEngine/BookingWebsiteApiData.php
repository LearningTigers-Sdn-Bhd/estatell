<?php

namespace App\Data\BookingEngine;

use App\Enums\BookingClientType;
use App\Models\BookingClient;
use App\Models\Property;
use Spatie\LaravelData\Data;

/**
 * What the API tab shows: the property's API address, which every request starts with, whether a token works and when it was made and last used, the return
 * addresses and the developer guide link. The token itself is shown once, as flash data.
 */
class BookingWebsiteApiData extends Data
{
    /**
     * @param  list<BookingReturnAddressData>  $returnAddresses
     */
    public function __construct(
        public string $apiAddress,
        public bool $hasToken,
        public ?string $tokenCreatedAt,
        public ?string $tokenLastUsedAt,
        public array $returnAddresses,
        public int $returnAddressLimit,
        public string $docsUrl,
    ) {}

    public static function forProperty(Property $property, int $returnAddressLimit): self
    {
        $client = BookingClient::query()
            ->where('type', BookingClientType::ApiOnly)
            ->where('property_id', $property->id)
            ->whereNull('revoked_at')
            ->first();
        $token = $client?->tokens()->latest('id')->first();

        return new self(
            apiAddress: route('api.booking.v1.properties.show', ['code' => $property->code]),
            hasToken: $token !== null,
            tokenCreatedAt: $token?->created_at?->toIso8601String(),
            tokenLastUsedAt: $token !== null ? $client->last_used_at?->toIso8601String() : null,
            returnAddresses: array_values($property->bookingReturnAddresses()->orderBy('url')->get()->map(BookingReturnAddressData::fromModel(...))->all()),
            returnAddressLimit: $returnAddressLimit,
            docsUrl: (string) config('estatell.booking_api_docs_url'),
        );
    }
}
