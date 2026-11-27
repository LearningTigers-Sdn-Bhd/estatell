import { formatMoney } from '@/components/pms/money-text';

type Route = App.Data.Transfers.TransferRouteData;

/**
 * What a transfer type adds on a route, for the second line of a Type option: "RM 35.00 · Airport shuttle", "No charge"
 * for In package, or "No price set: charge by hand". Used by the reservation Transfers tab and the booking form.
 */
export function transferPrice(
    route: Route | undefined,
    mode: string,
    currency: App.Data.Property.CurrencyData | null,
): string | undefined {
    if (route === undefined) {
        return undefined;
    }

    if (mode === 'included') {
        return 'No charge';
    }

    const amount =
        mode === 'shared'
            ? route.sharedAmount
            : mode === 'private'
              ? route.privateAmount
              : null;

    return amount === null
        ? 'No price set: charge by hand'
        : `${formatMoney(amount, currency)} · ${mode === 'shared' ? route.sharedChargeItemName : route.privateChargeItemName}`;
}
