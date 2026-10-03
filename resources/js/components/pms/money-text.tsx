/**
 * A decimal string (`2390.00`) shown as money in the property currency. Display only: the server calculates the amount.
 * `plain` leaves out the currency code where space is tight (`2,390.00`).
 */
export function MoneyText({
    amount,
    currency,
    plain = false,
}: {
    amount: string;
    currency: App.Enums.Currency | null;
    plain?: boolean;
}) {
    const value = Number(amount);

    return (
        <span className="tabular-nums">
            {plain
                ? new Intl.NumberFormat(undefined, {
                      minimumFractionDigits: 2,
                      maximumFractionDigits: 2,
                  }).format(value)
                : currency
                  ? new Intl.NumberFormat(undefined, {
                        style: 'currency',
                        currency,
                    }).format(value)
                  : value.toFixed(2)}
        </span>
    );
}
