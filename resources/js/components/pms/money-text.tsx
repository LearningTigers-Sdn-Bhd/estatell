/**
 * A decimal string (`2390.00`) shown as money in the property currency. Display only: the server calculates the amount.
 */
export function MoneyText({
    amount,
    currency,
}: {
    amount: string;
    currency: App.Enums.Currency | null;
}) {
    const value = Number(amount);

    return (
        <span className="tabular-nums">
            {currency
                ? new Intl.NumberFormat(undefined, {
                      style: 'currency',
                      currency,
                  }).format(value)
                : value.toFixed(2)}
        </span>
    );
}
