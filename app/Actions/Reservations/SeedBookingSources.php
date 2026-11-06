<?php

namespace App\Actions\Reservations;

use App\Enums\BookingSourceIcon;
use App\Enums\BookingSourceType;
use App\Enums\SwatchColor;
use App\Models\BookingSource;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

class SeedBookingSources
{
    /**
     * Phone, WhatsApp and Email are created only when the property has no booking sources yet, so a source the owner
     * deleted does not come back. The system rows (Company / Travel agent, Walk-in, Booking Engine) are restored by type
     * every time. Existing rows keep every property-owned value. The names match the backfill migrations. A property whose
     * own source already has the name Booking Engine keeps it, and the system row is named "Booking Engine (Online)".
     */
    public function __invoke(Property $property): void
    {
        DB::transaction(function () use ($property): void {
            if (! $property->bookingSources()->exists()) {
                foreach ([
                    ['Phone', BookingSourceIcon::Phone, SwatchColor::Gray],
                    ['WhatsApp', BookingSourceIcon::MessageCircle, SwatchColor::Green],
                    ['Email', BookingSourceIcon::Mail, SwatchColor::Blue],
                ] as $index => [$name, $icon, $color]) {
                    $this->create($property, $name, BookingSourceType::Direct, false, $index + 1, $icon, $color);
                }
            }

            $this->restoreSystem($property, 'Company / Travel agent', BookingSourceType::Company, 1000, BookingSourceIcon::BriefcaseBusiness, SwatchColor::Amber);
            $this->restoreSystem($property, 'Walk-in', BookingSourceType::WalkIn, 1001, BookingSourceIcon::Footprints, SwatchColor::Teal);
            $this->restoreSystem($property, $this->bookingEngineName($property), BookingSourceType::BookingEngine, 1002, BookingSourceIcon::Globe, SwatchColor::Violet);
        });
    }

    private function bookingEngineName(Property $property): string
    {
        $taken = $property->bookingSources()->where('is_system', false)->whereRaw('lower(name) = ?', ['booking engine'])->exists();

        return $taken ? 'Booking Engine (Online)' : 'Booking Engine';
    }

    private function restoreSystem(Property $property, string $name, BookingSourceType $type, int $sortOrder, BookingSourceIcon $icon, SwatchColor $color): void
    {
        if ($property->bookingSources()->where('type', $type)->where('is_system', true)->exists()) {
            return;
        }

        $this->create($property, $name, $type, true, $sortOrder, $icon, $color);
    }

    private function create(Property $property, string $name, BookingSourceType $type, bool $isSystem, int $sortOrder, BookingSourceIcon $icon, SwatchColor $color): void
    {
        $source = new BookingSource([
            'name' => $name,
            'type' => $type,
            'is_active' => true,
            'sort_order' => $sortOrder,
            'badge_icon' => $icon,
            'badge_color' => $color,
        ]);
        $source->forceFill(['property_id' => $property->id, 'is_system' => $isSystem])->save();
    }
}
