import {
    MultiCombobox,
    type MultiComboboxOption,
} from '@/components/pms/multi-combobox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';

type Tax = App.Data.Catalogues.ChargeItemTaxData;

/** A tax's name and rate, with "(inactive)" when it is disabled. */
export function taxLabel(
    tax: Tax,
    currency: App.Data.Property.CurrencyData,
): string {
    const rate =
        tax.rateType === 'percent'
            ? `${Number(tax.rate).toFixed(2)}%`
            : new Intl.NumberFormat(undefined, {
                  style: 'currency',
                  currency: currency.code,
              }).format(Number(tax.rate));

    return `${tax.name} · ${rate}${tax.enabled ? '' : ' (inactive)'}`;
}

/**
 * The taxes that apply to a charge item. Shared by the charge item edit page and the boat fare Sheet.
 */
export function ChargeItemTaxesField({
    taxes,
    currency,
    value,
    onValueChange,
    error,
}: {
    taxes: Tax[];
    currency: App.Data.Property.CurrencyData;
    value: string[];
    onValueChange: (value: string[]) => void;
    error?: string;
}) {
    const items: MultiComboboxOption[] = taxes.map((tax) => ({
        value: String(tax.id),
        label: taxLabel(tax, currency),
    }));

    return (
        <Field data-invalid={!!error}>
            <FieldLabel htmlFor="charge-item-taxes">Taxes</FieldLabel>
            <MultiCombobox
                id="charge-item-taxes"
                name="tax_ids"
                items={items}
                value={value}
                onValueChange={onValueChange}
                placeholder="No taxes"
                invalid={!!error}
            />
            <FieldDescription>
                {value.length === 0
                    ? 'No taxes are added to this item.'
                    : 'Taxes added automatically when this item is charged.'}
            </FieldDescription>
            <FieldError>{error}</FieldError>
        </Field>
    );
}
