import { expect, test } from 'vite-plus/test';
import { eachPaysHint } from './each-pays-hint';

test('a company with Each unit pays says nothing is billed to it', () => {
    expect(eachPaysHint('Acme Sdn Bhd', 'each_pays')).toBe(
        'Guests pay everything. Nothing is billed to Acme Sdn Bhd. Choose Master folio pays to bill the company.',
    );
});

test('the change variant points to Edit group', () => {
    expect(eachPaysHint('Acme Sdn Bhd', 'each_pays', 'change')).toBe(
        'This reservation is set to Each unit pays. Guests pay everything. Nothing is billed to Acme Sdn Bhd. Change it in Edit group.',
    );
});

test('no company or another billing mode gives no hint', () => {
    expect(eachPaysHint(null, 'each_pays')).toBeNull();
    expect(eachPaysHint('', 'each_pays')).toBeNull();
    expect(eachPaysHint('Acme Sdn Bhd', 'master_pays')).toBeNull();
    expect(eachPaysHint('Acme Sdn Bhd', 'master_pays_all')).toBeNull();
});
