/**
 * The hint for a company set on a reservation whose billing mode is Each unit pays: nothing is billed to the company
 * (its Master folio exists, but no rule routes to it) and the guests pay everything. It is derived in the browser from
 * the company and the mode that are already chosen, and is display only. `choose` is for the places that can change
 * the mode (the booking rail and Edit group); `change` is for the Change company Dialog, which cannot, and points to
 * Edit group. Returns null when there is no company or the mode is another one.
 */
export function eachPaysHint(
    companyName: string | null | undefined,
    billingMode: string,
    variant: 'choose' | 'change' = 'choose',
): string | null {
    if (!companyName || billingMode !== 'each_pays') {
        return null;
    }

    return variant === 'choose'
        ? `Guests pay everything. Nothing is billed to ${companyName}. Choose Master folio pays to bill the company.`
        : `This reservation is set to Each unit pays. Guests pay everything. Nothing is billed to ${companyName}. Change it in Edit group.`;
}
