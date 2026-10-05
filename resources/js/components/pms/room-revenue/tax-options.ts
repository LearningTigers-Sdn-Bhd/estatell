import type { MultiComboboxOption } from '@/components/pms/multi-combobox';

type Tax = App.Data.Catalogues.ChargeItemTaxData;

/** The rule of a tax in one line, for example "8.00% of the room price · added to the price · all guests". */
export function taxRule(tax: Tax, money: Intl.NumberFormat): string {
    const rate =
        tax.rateType === 'percent'
            ? `${Number(tax.rate).toFixed(2)}% of the room price`
            : `${money.format(Number(tax.rate))} per night`;
    const how = tax.isInclusive
        ? 'included in the price'
        : 'added to the price';
    const who = tax.foreignGuestsOnly ? 'foreign guests only' : 'all guests';

    return `${rate} · ${how} · ${who}`;
}

/** Multiselect options for the taxes of a room revenue code. A tax that is turned off can be chosen but is skipped. */
export function taxOptions(
    taxes: Tax[],
    money: Intl.NumberFormat,
): MultiComboboxOption[] {
    return taxes.map((tax) => ({
        value: String(tax.id),
        label: tax.enabled ? tax.name : `${tax.name} (off)`,
        description: tax.enabled
            ? taxRule(tax, money)
            : `${taxRule(tax, money)}. Off, not added until it is turned on.`,
    }));
}
