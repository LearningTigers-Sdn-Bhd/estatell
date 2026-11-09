import { usePage } from '@inertiajs/react';
import { Phone } from 'lucide-react';
import { lazy, Suspense } from 'react';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';

const PhoneField = lazy(() => import('@/components/pms/phone-field'));

/** The field's own words, for a screen in another language. Defaults to English. `:country` is replaced with the country name. */
export type PhoneInputLabels = {
    search: string;
    empty: string;
    invalid: string;
    invalidForCountry: string;
    countryCode: string;
};

export type PhoneInputProps = {
    id: string;
    /** Posts the value in a hidden input, for a form that submits without `onChange`. */
    name?: string;
    value: string;
    /** Gets the number in E.164 when it is valid, otherwise the text as typed. */
    onChange?: (value: string) => void;
    onBlur?: () => void;
    onFocus?: () => void;
    onKeyDown?: (event: React.KeyboardEvent<HTMLInputElement>) => void;
    placeholder?: string;
    invalid?: boolean;
    disabled?: boolean;
    /** ISO code that a number without a country code belongs to. Defaults to the current property's country. */
    defaultCountry?: string | null;
    labels?: PhoneInputLabels;
    /** Language of the country names. Defaults to English. */
    locale?: string;
    /** Extra attributes for the input, for example the combobox ARIA attributes of the guest search. */
    inputProps?: React.ComponentProps<'input'>;
};

/**
 * A phone number field with a country picker and live checks. The server still validates the number and saves it in E.164 form.
 * A read-only box shows the number until the phone rules load.
 */
export function PhoneInput({
    placeholder = '012-345 6789',
    defaultCountry,
    ...props
}: PhoneInputProps) {
    const { property } = usePage().props;

    return (
        <Suspense
            fallback={
                <InputGroup>
                    <InputGroupAddon>
                        <Phone aria-hidden />
                    </InputGroupAddon>
                    <InputGroupInput
                        id={props.id}
                        type="tel"
                        value={props.value}
                        placeholder={placeholder}
                        aria-invalid={props.invalid}
                        disabled={props.disabled}
                        readOnly
                    />
                </InputGroup>
            }
        >
            <PhoneField
                {...props}
                placeholder={placeholder}
                defaultCountry={defaultCountry ?? property?.countryCode}
            />
        </Suspense>
    );
}
