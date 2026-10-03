import { Phone } from 'lucide-react';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';

type Props = {
    id: string;
    name?: string;
    value: string;
    onChange: (value: string) => void;
    onBlur?: () => void;
    onFocus?: () => void;
    onKeyDown?: (event: React.KeyboardEvent<HTMLInputElement>) => void;
    placeholder?: string;
    invalid?: boolean;
    /** Extra attributes for the input, for example the combobox ARIA attributes of the guest search. */
    inputProps?: React.ComponentProps<'input'>;
};

/**
 * A phone number field. The server validates it and saves it in E.164 form.
 */
export function PhoneInput({
    id,
    name,
    value,
    onChange,
    onBlur,
    onFocus,
    onKeyDown,
    placeholder = '+60 12-345 6789',
    invalid,
    inputProps,
}: Props) {
    return (
        <InputGroup>
            <InputGroupAddon>
                <Phone aria-hidden />
            </InputGroupAddon>
            <InputGroupInput
                id={id}
                name={name}
                type="tel"
                inputMode="tel"
                autoComplete="tel"
                maxLength={30}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                onBlur={onBlur}
                onFocus={onFocus}
                onKeyDown={onKeyDown}
                placeholder={placeholder}
                aria-invalid={invalid}
                {...inputProps}
            />
        </InputGroup>
    );
}
