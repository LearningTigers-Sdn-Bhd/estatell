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
     * deleted does not come back. The two system rows (Company / Travel agent, Walk-in) are restored by type every time.
     * Existing rows keep every property-owned value. The names match the backfill migration.
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
        });
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
