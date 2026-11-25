/** Display helpers of the report charts. Display only: the server works the figures out. */

type Currency = App.Data.Property.CurrencyData | null;

const parseDay = (date: string) => {
    const [year, month, day = 1] = date.split('-').map(Number);

    return new Date(year, month - 1, day);
};

/** `2026-10-18` as "18 Oct", `2026-10` as "Oct 2026". */
export function axisDate(date: string): string {
    return date.length === 7
        ? parseDay(date).toLocaleDateString('en-GB', {
              month: 'short',
              year: 'numeric',
          })
        : parseDay(date).toLocaleDateString('en-GB', {
              day: 'numeric',
              month: 'short',
          });
}

/** `2026-10-18` as "Sun, 18 Oct 2026", `2026-10` as "October 2026". */
export function tooltipDate(date: string): string {
    return date.length === 7
        ? parseDay(date).toLocaleDateString('en-GB', {
              month: 'long',
              year: 'numeric',
          })
        : parseDay(date).toLocaleDateString('en-GB', {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          });
}

const compact = new Intl.NumberFormat('en', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

/** An axis tick of money: "MYR 12.5K". */
export function moneyTick(value: number, currency: Currency): string {
    return `${currency?.code ?? ''} ${compact.format(value)}`.trim();
}

/** A tooltip amount with the currency's decimals: "MYR 12,500.00". */
export function moneyText(value: number, currency: Currency): string {
    const decimals = currency?.decimals ?? 2;

    return `${currency?.code ?? ''} ${value.toLocaleString('en', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}`.trim();
}
