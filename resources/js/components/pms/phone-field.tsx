import { CheckIcon } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { CountryFlag } from '@/components/pms/country-flag';
import type {
    PhoneInputLabels,
    PhoneInputProps,
} from '@/components/pms/phone-input';
import {
    Combobox,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
    ComboboxTrigger,
} from '@/components/ui/combobox';
import { FieldError } from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    formatPhone,
    PHONE_ERROR_TEXT,
    phoneCountries,
    phoneError,
    phoneFormValue,
    readInternational,
    readPhoneValue,
    toE164,
    toPhoneCountry,
} from '@/lib/phone';
import type { PhoneCountry, PhoneParts } from '@/lib/phone';

const ENGLISH_LABELS: PhoneInputLabels = {
    search: 'Search countries',
    empty: 'No matching countries.',
    countryCode: 'Country code: :country',
    ...PHONE_ERROR_TEXT,
};

/**
 * The full phone field: a country picker with flags and a number that formats and validates as you type.
 * Load it through `PhoneInput`, because the phone rules are large.
 */
export default function PhoneField({
    id,
    name,
    value,
    onChange,
    onBlur,
    onFocus,
    onKeyDown,
    placeholder,
    invalid,
    disabled,
    defaultCountry,
    labels = ENGLISH_LABELS,
    locale = 'en',
    inputProps,
}: PhoneInputProps) {
    const baseCountry = toPhoneCountry(defaultCountry);
    const countries = useMemo(
        () => phoneCountries(baseCountry, locale),
        [baseCountry, locale],
    );
    const inputRef = useRef<HTMLInputElement>(null);
    const [phone, setPhone] = useState<PhoneParts>(() =>
        readPhoneValue(value, baseCountry),
    );
    const [edited, setEdited] = useState(false);
    const [blurred, setBlurred] = useState(false);
    const [seenValue, setSeenValue] = useState(value);

    const formValue = phoneFormValue(phone, baseCountry);

    // The parent replaced the value, for example when a guest is picked or the form resets.
    if (value !== seenValue) {
        setSeenValue(value);

        if (value !== formValue) {
            setPhone(readPhoneValue(value, baseCountry));
            setEdited(false);
            setBlurred(false);
        }
    }

    const hasText = phone.text.trim() !== '';
    const isValid = toE164(phone.text, phone.country) !== null;
    const liveError =
        edited && blurred && hasText && !isValid && !invalid
            ? phoneError(phone.text, phone.country, labels, locale)
            : null;
    const selected =
        countries.find((country) => country.code === phone.country) ?? null;

    function update(next: PhoneParts) {
        setPhone(next);
        setEdited(true);
        onChange?.(phoneFormValue(next, baseCountry));
    }

    function changeText(event: React.ChangeEvent<HTMLInputElement>) {
        const next = event.target.value;
        const international = readInternational(next);

        if (international) {
            update(international);

            return;
        }

        // Formatting moves the cursor to the end, so an edit in the middle waits until the field loses focus.
        const atEnd = event.target.selectionStart === next.length;

        update({
            country: phone.country,
            text: atEnd ? formatPhone(next, phone.country) : next,
        });
    }

    function changeCountry(next: PhoneCountry | null) {
        if (next) {
            update({
                country: next.code,
                text: formatPhone(phone.text, next.code),
            });
        }
    }

    function leave() {
        setBlurred(true);

        if (edited) {
            setPhone({
                country: phone.country,
                text: formatPhone(phone.text, phone.country),
            });
        }

        onBlur?.();
    }

    return (
        <>
            <InputGroup>
                <InputGroupAddon>
                    <Combobox
                        items={countries}
                        value={selected}
                        onValueChange={changeCountry}
                        itemToStringLabel={(country) =>
                            `${country.name} +${country.callingCode}`
                        }
                        itemToStringValue={(country) => country.code}
                        autoHighlight
                        disabled={disabled}
                    >
                        <ComboboxTrigger
                            render={
                                <InputGroupButton
                                    variant="ghost"
                                    size="xs"
                                    aria-label={labels.countryCode.replace(
                                        ':country',
                                        selected?.name ?? phone.country,
                                    )}
                                />
                            }
                        >
                            <CountryFlag code={phone.country} />
                            {selected && (
                                <span className="tabular-nums">
                                    +{selected.callingCode}
                                </span>
                            )}
                        </ComboboxTrigger>
                        <ComboboxContent className="w-72" finalFocus={inputRef}>
                            <ComboboxInput
                                placeholder={labels.search}
                                showTrigger={false}
                            />
                            <ComboboxEmpty>{labels.empty}</ComboboxEmpty>
                            <ComboboxList>
                                {(country: PhoneCountry) => (
                                    <ComboboxItem
                                        key={country.code}
                                        value={country}
                                    >
                                        <span className="flex min-w-0 items-center gap-2">
                                            <CountryFlag code={country.code} />
                                            <span className="truncate">
                                                {country.name}
                                            </span>
                                            <span className="text-muted-foreground tabular-nums">
                                                +{country.callingCode}
                                            </span>
                                        </span>
                                    </ComboboxItem>
                                )}
                            </ComboboxList>
                        </ComboboxContent>
                    </Combobox>
                </InputGroupAddon>
                <InputGroupInput
                    ref={inputRef}
                    id={id}
                    type="tel"
                    inputMode="tel"
                    autoComplete="tel"
                    maxLength={30}
                    value={phone.text}
                    onChange={changeText}
                    onBlur={leave}
                    onFocus={onFocus}
                    onKeyDown={onKeyDown}
                    placeholder={placeholder}
                    disabled={disabled}
                    aria-invalid={invalid || !!liveError}
                    {...inputProps}
                />
                {edited && isValid && !invalid && (
                    <InputGroupAddon align="inline-end">
                        <CheckIcon aria-hidden className="text-success" />
                    </InputGroupAddon>
                )}
            </InputGroup>
            {name && (
                <input
                    type="hidden"
                    name={name}
                    value={edited ? formValue : value}
                    disabled={disabled}
                />
            )}
            <FieldError>{liveError}</FieldError>
        </>
    );
}
