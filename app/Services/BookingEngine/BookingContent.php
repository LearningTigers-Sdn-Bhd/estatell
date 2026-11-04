<?php

namespace App\Services\BookingEngine;

use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the API shows about a property. It loads what the resources read. Never add folios, guests, staff data or commission.
 */
class BookingContent
{
    public function property(Property $property): Property
    {
        return $property->load([
            'settings',
            'bookingSite',
            'media',
            'amenities' => fn ($amenities) => $amenities->with('category')->orderBy('position'),
            'nearbyPlaces' => fn ($places) => $places->wherePivot('active', true),
            'roomRevenuePolicies' => fn ($policies) => $policies->whereIn('code', [SystemTransactionCode::CancellationRevenue->value, SystemTransactionCode::NoShowRevenue->value]),
        ]);
    }

    /**
     * The active room types that a public rate plan sells.
     *
     * @return Collection<int, RoomType>
     */
    public function roomTypes(Property $property): Collection
    {
        return RoomType::query()
            ->where('property_id', $property->id)
            ->active()
            ->whereHas('ratePlans', function (Builder $plans): void {
                /** @var Builder<RatePlan> $plans */
                $plans->bookableOnline();
            })
            ->ordered()
            ->with(['media', 'amenities' => fn ($amenities) => $amenities->with('category')->orderBy('position')])
            ->get();
    }

    public function site(Property $property): Property
    {
        return $property->load(['bookingSite.media', 'bookingSite.siteTemplate.slots']);
    }
}
