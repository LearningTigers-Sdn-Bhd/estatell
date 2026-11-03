import { expect, test } from 'vite-plus/test';
import { emptyGuest } from './booking-guests';
import {
    fieldsFromCard,
    isEmptyGuest,
    mykadToggleState,
    pickCardFields,
    targetRowKey,
} from './guest-mykad';

const rows = [
    { key: 'booker', empty: false },
    { key: 'second', empty: true },
    { key: 'third', empty: true },
];

test('the first empty row is the target by default', () => {
    expect(targetRowKey({ mode: 'auto' }, rows)).toBe('second');
});

test('no empty row means no target', () => {
    expect(
        targetRowKey({ mode: 'auto' }, [{ key: 'booker', empty: false }]),
    ).toBeNull();
});

test('a row staff turned on stays the target, even when it is filled', () => {
    expect(targetRowKey({ mode: 'row', key: 'booker' }, rows)).toBe('booker');
});

test('a turned-on row that is gone falls back to the first empty row', () => {
    expect(targetRowKey({ mode: 'row', key: 'removed' }, rows)).toBe('second');
});

test('off stays off while empty rows wait', () => {
    expect(targetRowKey({ mode: 'off' }, rows)).toBeNull();
});

test('a typed name or a linked guest is not an empty row', () => {
    expect(isEmptyGuest(emptyGuest('', 'MY'))).toBe(true);
    expect(isEmptyGuest({ ...emptyGuest(), full_name: 'Aminah' })).toBe(false);
});

test('the toggle shows on first, then filled', () => {
    expect(mykadToggleState(true, true)).toBe('on');
    expect(mykadToggleState(false, true)).toBe('filled');
    expect(mykadToggleState(false, false)).toBe('off');
});

test('clear values leave out what the card does not fill', () => {
    const guest = {
        ...emptyGuest(),
        full_name: 'Aminah',
        phone: '+60 12-345 6789',
    };
    const picked = pickCardFields(guest);

    expect(picked.full_name).toBe('Aminah');
    expect(picked).not.toHaveProperty('phone');
    expect(picked).not.toHaveProperty('email');
    expect(picked).not.toHaveProperty('stays_in');
});

test('a card fills the guest fields as Malaysian', () => {
    const fields = fieldsFromCard({
        name: 'NUR AISYAH BINTI RAHMAN',
        ic_number: '900101141234',
        formatted_ic: '900101-14-1234',
        date_of_birth: '1990-01-01',
        gender: 'female',
        nationality_code: 'MY',
        address_line1: '12 JALAN MAWAR',
        address_line2: '',
        city: 'KOTA KINABALU',
        state: 'Sabah',
        postal_code: '88000',
        country_code: 'MY',
        photo_base64: null,
    });

    expect(fields.full_name).toBe('NUR AISYAH BINTI RAHMAN');
    expect(fields.nationality_code).toBe('MY');
    expect(fields.country_code).toBe('MY');
});
