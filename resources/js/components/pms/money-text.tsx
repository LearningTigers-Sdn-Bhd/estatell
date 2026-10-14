/**
 * A decimal string (`2390.00`) shown as money in the property currency. Display only: the server calculates the amount.
 * `plain` leaves out the currency code where space is tight (`2,390.00`). Decimals follow the currency.
 */
export function MoneyText({
    amount,
    currency,
    plain = false,
}: {
    amount: string;
    currency: App.Data.Property.CurrencyData | null;
    plain?: boolean;
}) {
    const value = Number(amount);

    return (
        <span className="tabular-nums">
            {plain
                ? new Intl.NumberFormat(undefined, {
                      minimumFractionDigits: currency?.decimals ?? 2,
                      maximumFractionDigits: currency?.decimals ?? 2,
                  }).format(value)
                : currency
                  ? new Intl.NumberFormat(undefined, {
                        style: 'currency',
                        currency: currency.code,
                    }).format(value)
                  : value.toFixed(2)}
        </span>
    );
}
