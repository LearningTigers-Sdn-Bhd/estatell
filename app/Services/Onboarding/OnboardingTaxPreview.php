<?php

namespace App\Services\Onboarding;

use App\Data\Catalogues\RoomRevenuePreviewData;
use App\Enums\TaxRateType;
use App\Models\Property;
use App\Models\Tax;
use App\Services\Catalogues\RoomRevenuePreview;
use Illuminate\Support\Collection;

/**
 * The sample room night for the Taxes table as it stands on screen, before it is saved. The rows become taxes in
 * memory: only enabled rows ticked "Applies to rooms" count, and a new row gets an id after every saved one.
 */
class OnboardingTaxPreview
{
    private const NEW_ROW_ID_OFFSET = 1_000_000_000;

    public function __construct(private RoomRevenuePreview $roomRevenuePreview) {}

    /**
     * @param  list<array{id: int|null, name: string, rate_type: TaxRateType, rate: string, is_inclusive: bool, foreign_guests_only: bool, collect_at_check_in: bool, registration_number: string|null, enabled: bool, applies_to_rooms: bool}>  $rows
     */
    public function forRows(Property $property, array $rows): RoomRevenuePreviewData
    {
        /** @var Collection<int, Tax> $taxes */
        $taxes = collect($rows)
            ->filter(fn (array $row): bool => $row['enabled'] && $row['applies_to_rooms'])
            ->map(function (array $row, int $index) use ($property): Tax {
                $tax = new Tax([
                    'name' => $row['name'],
                    'rate_type' => $row['rate_type'],
                    'rate' => $row['rate'],
                    'is_inclusive' => $row['is_inclusive'],
                    'foreign_guests_only' => $row['foreign_guests_only'],
                    'enabled' => true,
                ]);
                $tax->id = $row['id'] ?? self::NEW_ROW_ID_OFFSET + $index;
                $tax->property_id = $property->id;
                $tax->transaction_code_id = 0;

                return $tax;
            })
            ->values();

        return RoomRevenuePreviewData::fromCalculation($this->roomRevenuePreview->calculateTaxes($property, $taxes));
    }
}
