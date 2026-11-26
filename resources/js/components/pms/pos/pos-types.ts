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
    imageUrl?: string | null;
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

export const POS_CATEGORY_COLORS: {
    value: string;
    label: string;
    swatchClass: string;
    badgeClass: string;
}[] = [
    {
        value: 'category-1',
        label: 'Emerald',
        swatchClass: 'bg-category-1',
        badgeClass: 'bg-category-1 text-category-1-foreground',
    },
    {
        value: 'category-2',
        label: 'Sky',
        swatchClass: 'bg-category-2',
        badgeClass: 'bg-category-2 text-category-2-foreground',
    },
    {
        value: 'category-3',
        label: 'Amber',
        swatchClass: 'bg-category-3',
        badgeClass: 'bg-category-3 text-category-3-foreground',
    },
    {
        value: 'category-4',
        label: 'Violet',
        swatchClass: 'bg-category-4',
        badgeClass: 'bg-category-4 text-category-4-foreground',
    },
    {
        value: 'category-5',
        label: 'Rose',
        swatchClass: 'bg-category-5',
        badgeClass: 'bg-category-5 text-category-5-foreground',
    },
    {
        value: 'category-6',
        label: 'Teal',
        swatchClass: 'bg-category-6',
        badgeClass: 'bg-category-6 text-category-6-foreground',
    },
    {
        value: 'category-7',
        label: 'Orange',
        swatchClass: 'bg-category-7',
        badgeClass: 'bg-category-7 text-category-7-foreground',
    },
    {
        value: 'category-8',
        label: 'Indigo',
        swatchClass: 'bg-category-8',
        badgeClass: 'bg-category-8 text-category-8-foreground',
    },
    {
        value: 'category-9',
        label: 'Lime',
        swatchClass: 'bg-category-9',
        badgeClass: 'bg-category-9 text-category-9-foreground',
    },
    {
        value: 'category-10',
        label: 'Cobalt',
        swatchClass: 'bg-category-10',
        badgeClass: 'bg-category-10 text-category-10-foreground',
    },
    {
        value: 'category-11',
        label: 'Blush',
        swatchClass: 'bg-category-11',
        badgeClass: 'bg-category-11 text-category-11-foreground',
    },
    {
        value: 'category-12',
        label: 'Bronze',
        swatchClass: 'bg-category-12',
        badgeClass: 'bg-category-12 text-category-12-foreground',
    },
];

export function categorySwatchClass(token: string): string {
    return (
        POS_CATEGORY_COLORS.find((color) => color.value === token)
            ?.swatchClass ?? 'bg-category-1'
    );
}

const TENDER_LABELS: Record<string, string> = {
    cash: 'Cash',
    card: 'Card',
    duitnow_qr: 'DuitNow QR',
    e_wallet: 'E-wallet',
    bank_transfer: 'Bank transfer',
    room_charge: 'Room charge',
    voucher: 'Voucher',
    other: 'Other',
};

const MOVEMENT_LABELS: Record<string, string> = {
    float_in: 'Float in',
    safe_drop: 'Safe drop',
    payout: 'Payout',
    no_sale_open: 'No-sale open',
};

export function tenderTypeLabel(value: string): string {
    return TENDER_LABELS[value] ?? value.replace(/_/g, ' ');
}

export function cashMovementKindLabel(value: string): string {
    return MOVEMENT_LABELS[value] ?? value.replace(/_/g, ' ');
}
