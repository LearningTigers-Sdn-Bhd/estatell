import {
    AsYouType,
    getCountries,
    getCountryCallingCode,
    isSupportedCountry,
    parsePhoneNumberFromString,
} from 'libphonenumber-js/max';
import type { CountryCode } from 'libphonenumber-js/max';

/**
 * Phone number helpers for `PhoneField`. The `max` rules are large, so only that lazy-loaded field imports this file.
 * These are quick checks while typing: the server still validates the number and saves it in E.164 form.
 */

export type { CountryCode };

export type PhoneCountry = {
    code: CountryCode;
    name: string;
    callingCode: string;
};

/** The error wording. `:country` is replaced with the country name. */
export type PhoneErrorText = {
    invalid: string;
    invalidForCountry: string;
};

export const PHONE_ERROR_TEXT: PhoneErrorText = {
    invalid: 'Enter a valid phone number.',
    invalidForCountry: 'Enter a valid :country phone number.',
};

export type PhoneParts = {
    country: CountryCode;
    text: string;
};

const FALLBACK_COUNTRY: CountryCode = 'MY';

function countryName(code: CountryCode, locale: string): string {
    return new Intl.DisplayNames([locale], { type: 'region' }).of(code) ?? code;
}

/** The ISO code as a supported phone country, or Malaysia when it is missing or has no phone code. */
export function toPhoneCountry(code: string | null | undefined): CountryCode {
    return code && isSupportedCountry(code) ? code : FALLBACK_COUNTRY;
}

/** Every country with a phone code, A to Z, with the given country first. */
export function phoneCountries(
    first: CountryCode,
    locale = 'en',
): PhoneCountry[] {
    const countries = getCountries()
        .map((code) => ({
            code,
            name: countryName(code, locale),
            callingCode: getCountryCallingCode(code),
        }))
        .sort((a, b) => a.name.localeCompare(b.name, locale));

    return [
        ...countries.filter((country) => country.code === first),
        ...countries.filter((country) => country.code !== first),
    ];
}

/** The number as it is typed, such as `012-345 6789`. */
export function formatPhone(text: string, country: CountryCode): string {
    return new AsYouType(country).input(text);
}

/** The number in E.164, such as `+60123456789`, or `null` if it is not valid. */
export function toE164(text: string, country: CountryCode): string | null {
    const parsed = parsePhoneNumberFromString(text, country);

    return parsed?.isValid() ? parsed.number : null;
}

/** The error for a number that is not valid, or `null` if it is valid. */
export function phoneError(
    text: string,
    country: CountryCode,
    wording: PhoneErrorText = PHONE_ERROR_TEXT,
    locale = 'en',
): string | null {
    if (toE164(text, country)) {
        return null;
    }

    return text.trimStart().startsWith('+')
        ? wording.invalid
        : wording.invalidForCountry.replace(
              ':country',
              countryName(country, locale),
          );
}

/** The country and local number of a complete number that starts with `+`, otherwise `null`. */
export function readInternational(text: string): PhoneParts | null {
    if (!text.trimStart().startsWith('+')) {
        return null;
    }

    const parsed = parsePhoneNumberFromString(text);

    return parsed?.country && parsed.isValid()
        ? { country: parsed.country, text: parsed.formatNational() }
        : null;
}

/** Splits a saved value for the field. A value that cannot be read stays as typed, under the default country. */
export function readPhoneValue(
    value: string,
    defaultCountry: CountryCode,
): PhoneParts {
    return readInternational(value) ?? { country: defaultCountry, text: value };
}

/**
 * The value the form receives: E.164 when valid, otherwise the typed text for the server to reject or read.
 * The server reads a number without a country code as a local number, so an invalid number under another country
 * keeps that country's code. It cannot then pass as a local number by mistake.
 */
export function phoneFormValue(
    { country, text }: PhoneParts,
    defaultCountry: CountryCode,
): string {
    const typed = text.trim();
    const e164 = toE164(typed, country);

    if (e164 || typed === '' || typed.startsWith('+')) {
        return e164 ?? typed;
    }

    return country === defaultCountry
        ? typed
        : `+${getCountryCallingCode(country)} ${typed}`;
}
