export type PosStaff = { id: number; name: string };

export type PosVariant = {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    pricingType: string;
    price: string;
    stockCountdown: number | null;
    soldOut: boolean;
};

export type PosModifierOption = {
    id: number;
    name: string;
    internalName: string | null;
    priceDelta: string;
};

export type PosModifierGroup = {
    id: number;
    name: string;
    minSelect: number;
    maxSelect: number | null;
    maxPerOption: number;
    freeIncludedCount: number;
    options: PosModifierOption[];
};

export type PosComboSlot = {
    id: number;
    name: string;
    min: number;
    max: number;
    options: { variantId: number; name: string; upcharge: string }[];
};

export type PosItem = {
    id: number;
    name: string;
    description: string | null;
    mealPeriodId: number | null;
    soldByWeight: boolean;
    weightUnit: string | null;
    weightUnitDivisor: string | null;
    isCombo: boolean;
    comboSlots: PosComboSlot[];
    variants: PosVariant[];
    modifierGroups: PosModifierGroup[];
};

export type PosCategory = {
    id: number;
    name: string;
    colorToken: string;
    items: PosItem[];
};

export type PosDiningOption = {
    id: number;
    name: string;
    kind: string;
    isDefault: boolean;
};

export type PosPaymentMethod = {
    id: number;
    paymentMethodId: number;
    name: string;
    tenderType: string;
    pulseDrawer: boolean;
};

/** The `OutletMenuSnapshot` the register sells from. */
export type PosMenu = {
    version: string;
    categories: PosCategory[];
    diningOptions: PosDiningOption[];
    paymentMethods: PosPaymentMethod[];
};

export type PosCan = {
    sell: boolean;
    shift: boolean;
    void: boolean;
    discount: boolean;
    refund: boolean;
};

export type PosVoidReason = {
    id: number;
    label: string;
    requires_manager_pin: boolean;
};

export type PosCurrency = App.Data.Property.CurrencyData | null;

/** The accessible name of a kitchen status for the ticket. */
export function lineStatusLabel(line: App.Data.Pos.PosOrderLineData): string {
    if (line.isVoided) {
        return 'Voided';
    }

    return line.isDraft ? 'Draft' : 'Sent';
}
