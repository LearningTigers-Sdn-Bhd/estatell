import type { MultiComboboxOption } from '@/components/pms/multi-combobox';

/**
 * A room type as a combobox option: the name, how it is sold, and the reason when the plan's pricing basis cannot sell it.
 */
export function roomTypeOption(
    roomType: App.Data.Rates.NewRatePlanRoomTypeData,
    fits: boolean,
    pricingLabel: string,
): MultiComboboxOption {
    const soldBy = `Sold by ${roomType.sellUnitLabel.toLowerCase()}`;

    return {
        value: String(roomType.id),
        label: roomType.name,
        description: fits
            ? soldBy
            : `${soldBy}. Not available with ${pricingLabel.toLowerCase()} pricing.`,
        disabled: !fits,
    };
}
