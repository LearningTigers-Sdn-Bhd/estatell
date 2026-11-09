import { describe, expect, it } from 'vite-plus/test';
import {
    formatPhone,
    phoneCountries,
    phoneError,
    phoneFormValue,
    readInternational,
    readPhoneValue,
    toE164,
    toPhoneCountry,
} from '@/lib/phone';

describe('phone helpers', () => {
    it('formats a local number as it is typed', () => {
        expect(formatPhone('0123456789', 'MY')).toBe('012-345 6789');
    });

    it('converts a valid local number to E.164', () => {
        expect(toE164('012-345 6789', 'MY')).toBe('+60123456789');
    });

    it('returns no E.164 for an incomplete number', () => {
        expect(toE164('012', 'MY')).toBeNull();
    });

    it('names the country in the error for a local number', () => {
        expect(phoneError('012', 'MY')).toBe(
            'Enter a valid Malaysia phone number.',
        );
        expect(phoneError('012-345 6789', 'MY')).toBeNull();
    });

    it('uses the given wording and language in the error', () => {
        expect(
            phoneError(
                '012',
                'MY',
                {
                    invalid: 'Tidak sah.',
                    invalidForCountry: 'Nombor :country tidak sah.',
                },
                'ms',
            ),
        ).toBe('Nombor Malaysia tidak sah.');
    });

    it('names the countries in the given language', () => {
        expect(phoneCountries('DE', 'ms')[0].name).toBe('Jerman');
    });

    it('reads the country from a complete international number', () => {
        expect(readInternational('+6591234567')).toEqual({
            country: 'SG',
            text: '9123 4567',
        });
        expect(readInternational('+65')).toBeNull();
        expect(readInternational('0123456789')).toBeNull();
    });

    it('keeps a saved value it cannot read as typed', () => {
        expect(readPhoneValue('call the front desk', 'MY')).toEqual({
            country: 'MY',
            text: 'call the front desk',
        });
    });

    it('sends E.164 for a valid number', () => {
        expect(phoneFormValue({ country: 'SG', text: '9123 4567' }, 'MY')).toBe(
            '+6591234567',
        );
    });

    it('sends an invalid local number as typed', () => {
        expect(phoneFormValue({ country: 'MY', text: '012-3' }, 'MY')).toBe(
            '012-3',
        );
    });

    it('keeps the calling code of an invalid number under another country', () => {
        expect(phoneFormValue({ country: 'SG', text: '12' }, 'MY')).toBe(
            '+65 12',
        );
    });

    it('sends an empty value when nothing is typed', () => {
        expect(phoneFormValue({ country: 'SG', text: '  ' }, 'MY')).toBe('');
    });

    it('falls back to Malaysia for a country with no phone code', () => {
        expect(toPhoneCountry('SG')).toBe('SG');
        expect(toPhoneCountry('AQ')).toBe('MY');
        expect(toPhoneCountry(null)).toBe('MY');
    });

    it('lists the given country first', () => {
        expect(phoneCountries('SG')[0]).toEqual({
            code: 'SG',
            name: 'Singapore',
            callingCode: '65',
        });
    });
});
