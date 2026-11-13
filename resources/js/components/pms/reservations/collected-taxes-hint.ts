import type { EnumOption } from '@/components/pms/enum-select';

/**
 * Who pays the taxes collected at check-in (Tourism Tax, Heritage Fee: any tax with Collect at check-in on) on a
 * booking with a company. The choice is a `CollectedTaxesPayer` value, or "" to follow the company's default. All of it
 * is derived in the browser from what is already picked, and is display only: the server decides where the taxes post.
 * See docs/12-reservations/02-group-bookings.md#taxes-at-check-in.
 */

/** True when the choice can matter: a company is picked, the property has such taxes, and the company is billed. */
export function showsCollectedTaxes(
    companyName: string | null | undefined,
    taxes: string[],
    billingMode: string,
): boolean {
    return !!companyName && taxes.length > 0 && billingMode !== 'each_pays';
}

/** True when the company pays, from the choice or, for "", from the company's default. */
export function companyPaysCollectedTaxes(
    choice: string,
    companyDefaultPays: boolean,
): boolean {
    return choice === '' ? companyDefaultPays : choice === 'company';
}

/** The label of the empty choice, for example "Company default (Guest pays)". */
export function companyDefaultLabel(
    payers: EnumOption[],
    companyDefaultPays: boolean,
): string {
    const label = payers.find(
        (payer) => payer.value === (companyDefaultPays ? 'company' : 'guest'),
    )?.label;

    return label ? `Company default (${label})` : 'Company default';
}

/** "Tourism Tax", "Tourism Tax and Heritage Fee", "A, B and C". */
export function taxNamesText(taxes: string[]): string {
    return taxes.length <= 1
        ? (taxes[0] ?? '')
        : `${taxes.slice(0, -1).join(', ')} and ${taxes[taxes.length - 1]}`;
}

/** The hint under the select: where the taxes go, and whether that is the company's default or this booking's own. */
export function collectedTaxesHint(
    taxes: string[],
    companyName: string,
    choice: string,
    companyDefaultPays: boolean,
): string {
    const names = taxNamesText(taxes);
    const where = companyPaysCollectedTaxes(choice, companyDefaultPays)
        ? `${names} ${taxes.length === 1 ? 'goes' : 'go'} with the room charge to ${companyName}.`
        : `Guests pay ${names} at check-in on their own folio.`;

    return choice === ''
        ? `${where} This is ${companyName}'s default.`
        : `${where} For this booking only.`;
}
