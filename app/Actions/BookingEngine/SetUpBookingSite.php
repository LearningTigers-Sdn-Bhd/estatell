<?php

namespace App\Actions\BookingEngine;

use App\Enums\BookingEngineMode;
use App\Enums\PaymentMode;
use App\Models\Property;
use App\Models\PropertyBookingSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create the property's booking site row the first time the module is opened, and give the property its web address
 * slug from its name. A new site takes Test payments until someone switches it to Live. Safe to call again: it changes nothing once the row and the slug exist.
 */
class SetUpBookingSite
{
    public function __invoke(Property $property): PropertyBookingSite
    {
        return DB::transaction(function () use ($property): PropertyBookingSite {
            $site = PropertyBookingSite::query()->firstOrCreate(
                ['property_id' => $property->id],
                ['mode' => BookingEngineMode::Microsite, 'payment_mode' => PaymentMode::Test],
            );

            if ($property->slug === null) {
                $property->forceFill(['slug' => $this->uniqueSlug($property)])->save();
            }

            return $site;
        });
    }

    private function uniqueSlug(Property $property): string
    {
        $base = Str::limit(Str::slug($property->name), 50, '');
        $base = $base === '' ? 'property' : $base;
        $slug = $base;
        $number = 2;

        while (Property::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$number++;
        }

        return $slug;
    }
}
