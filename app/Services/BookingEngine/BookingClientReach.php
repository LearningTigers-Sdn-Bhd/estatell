<?php

namespace App\Services\BookingEngine;

use App\Enums\BookingClientType;
use App\Enums\BookingEngineMode;
use App\Features\BookingEngine;
use App\Models\BookingClient;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * Which properties a booking client can reach. A property must not be suspended and must have the Booking engine flag on.
 * A Microsite client reaches published sites in Microsite mode, an API only client its one property in API only mode.
 * A Demo client reaches nothing until the demo item is built. See docs/28-api-access/04-booking-engine-microsite.md.
 */
class BookingClientReach
{
    /**
     * @return Collection<int, Property>
     */
    public function properties(BookingClient $client): Collection
    {
        $properties = $this->query($client)->orderBy('name')->get();

        if ($properties->isEmpty()) {
            return new Collection;
        }

        Feature::for($properties->all())->load([BookingEngine::class]);

        return $properties->filter(fn (Property $property): bool => Feature::for($property)->active(BookingEngine::class))->values()->toBase();
    }

    public function propertyByCode(BookingClient $client, string $code): ?Property
    {
        return $this->active($this->query($client)->where('code', mb_strtoupper($code))->first());
    }

    public function propertyBySlug(BookingClient $client, string $slug): ?Property
    {
        return $this->active($this->query($client)->where('slug', mb_strtolower($slug))->first());
    }

    /**
     * @return Builder<Property>
     */
    private function query(BookingClient $client): Builder
    {
        $query = Property::query()->whereNull('suspended_at');

        return match ($client->type) {
            BookingClientType::Microsite => $query->whereHas('bookingSite', fn (Builder $site) => $site->where('mode', BookingEngineMode::Microsite)->whereNotNull('published_at')),
            BookingClientType::ApiOnly => $query->whereKey($client->property_id)->whereHas('bookingSite', fn (Builder $site) => $site->where('mode', BookingEngineMode::ApiOnly)),
            BookingClientType::Demo => $query->whereRaw('1 = 0'),
        };
    }

    private function active(?Property $property): ?Property
    {
        return $property !== null && Feature::for($property)->active(BookingEngine::class) ? $property : null;
    }
}
