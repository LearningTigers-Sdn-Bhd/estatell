import { renderToStaticMarkup } from 'react-dom/server';
import { expect, test } from 'vite-plus/test';
import PhoneField from '@/components/pms/phone-field';

test('shows a saved number in local format under its own country', () => {
    const html = renderToStaticMarkup(
        <PhoneField id="phone" value="+6591234567" defaultCountry="MY" />,
    );

    expect(html).toContain('value="9123 4567"');
    expect(html).toContain('Country code: Singapore');
});

test('posts an unedited saved value exactly as it was saved', () => {
    const html = renderToStaticMarkup(
        <PhoneField
            id="phone"
            name="contact_phone"
            value="03-1234 5678 ext 2"
            defaultCountry="MY"
        />,
    );

    expect(html).toContain(
        '<input type="hidden" name="contact_phone" value="03-1234 5678 ext 2"',
    );
});

test('does not flag an unedited number that is not valid', () => {
    const html = renderToStaticMarkup(
        <PhoneField id="phone" value="12" defaultCountry="MY" />,
    );

    expect(html).not.toContain('Enter a valid');
    expect(html).not.toContain('aria-invalid="true"');
});
