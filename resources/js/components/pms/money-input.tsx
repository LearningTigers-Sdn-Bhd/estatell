import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
    InputGroupText,
} from '@/components/ui/input-group';

/**
 * An amount typed as a decimal string (`2390.00`) with the property currency in front. The server checks and rounds it.
 */
export function MoneyInput({
    id,
    value,
    onChange,
    currency,
    placeholder = '0.00',
    invalid,
    disabled,
}: {
    id?: string;
    value: string;
    onChange: (value: string) => void;
    currency: App.Enums.Currency | null;
    placeholder?: string;
    invalid?: boolean;
    disabled?: boolean;
}) {
    return (
        <InputGroup>
            {currency && (
                <InputGroupAddon>
                    <InputGroupText>{currency}</InputGroupText>
                </InputGroupAddon>
            )}
            <InputGroupInput
                id={id}
                inputMode="decimal"
                autoComplete="off"
                className="tabular-nums"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder}
                disabled={disabled}
                aria-invalid={invalid}
            />
        </InputGroup>
    );
}
