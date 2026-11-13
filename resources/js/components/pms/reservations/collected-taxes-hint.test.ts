import { expect, test } from 'vite-plus/test';
import {
    collectedTaxesHint,
    companyDefaultLabel,
    showsCollectedTaxes,
    taxNamesText,
} from './collected-taxes-hint';

const payers = [
    { value: 'company', label: 'Company pays' },
    { value: 'guest', label: 'Guest pays' },
];

test('the default follows the company and says so', () => {
    expect(
        collectedTaxesHint(['Heritage Fee'], 'Borneo Trails', '', false),
    ).toBe(
        "Guests pay Heritage Fee at check-in on their own folio. This is Borneo Trails's default.",
    );
    expect(companyDefaultLabel(payers, true)).toBe(
        'Company default (Company pays)',
    );
});

test('a choice of the booking overrides the default for this booking only', () => {
    expect(
        collectedTaxesHint(
            ['Tourism Tax', 'Heritage Fee'],
            'Borneo Trails',
            'company',
            false,
        ),
    ).toBe(
        'Tourism Tax and Heritage Fee go with the room charge to Borneo Trails. For this booking only.',
    );
    expect(
        collectedTaxesHint(['Tourism Tax'], 'Borneo Trails', 'guest', true),
    ).toBe(
        'Guests pay Tourism Tax at check-in on their own folio. For this booking only.',
    );
});

test('tax names join with commas and "and"', () => {
    expect(taxNamesText(['A', 'B', 'C'])).toBe('A, B and C');
});

test('it shows only with a company, taxes and a billed company', () => {
    expect(showsCollectedTaxes('Acme', ['Tourism Tax'], 'master_pays')).toBe(
        true,
    );
    expect(showsCollectedTaxes(null, ['Tourism Tax'], 'master_pays')).toBe(
        false,
    );
    expect(showsCollectedTaxes('Acme', [], 'master_pays')).toBe(false);
    expect(showsCollectedTaxes('Acme', ['Tourism Tax'], 'each_pays')).toBe(
        false,
    );
});
