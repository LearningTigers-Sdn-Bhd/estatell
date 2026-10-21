import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
    InputGroupText,
} from '@/components/ui/input-group';

/**
 * An amount typed as a decimal string (`2390.00`) with the property currency in front. The placeholder follows the currency decimals. The server checks and rounds it.
 */
export function MoneyInput({
    id,
    value,
    onChange,
    currency,
    placeholder,
    invalid,
    disabled,
    'aria-label': ariaLabel,
}: {
    id?: string;
    value: string;
    onChange: (value: string) => void;
    currency: App.Data.Property.CurrencyData | null;
    placeholder?: string;
    invalid?: boolean;
    disabled?: boolean;
    /** The name of an input that has no visible label, for example a cell of a price table. */
    'aria-label'?: string;
}) {
    const decimals = currency?.decimals ?? 2;
    const hint =
        placeholder ?? (decimals === 0 ? '0' : `0.${'0'.repeat(decimals)}`);

    return (
        <InputGroup>
            {currency && (
                <InputGroupAddon>
                    <InputGroupText>{currency.code}</InputGroupText>
                </InputGroupAddon>
            )}
            <InputGroupInput
                id={id}
                inputMode="decimal"
                autoComplete="off"
                className="tabular-nums"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder={hint}
                disabled={disabled}
                aria-invalid={invalid}
                aria-label={ariaLabel}
            />
        </InputGroup>
    );
}
