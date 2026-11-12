import { dataTableColumns } from '@/components/pms/data-table';
import { DateText } from '@/components/pms/date-text';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyText } from '@/components/pms/money-text';

type Row = App.Data.Commissions.CommissionBookingRowData;
type Currency = App.Data.Property.CurrencyData | null;

const column = dataTableColumns<Row>();

/**
 * The columns of the admin Commissions and the property Payouts Bookings tabs: one commission column per service. The
 * property page leaves out the Property column and passes its `currency`: the money headers then carry the currency
 * (`Value (MYR)`) and the cells show plain amounts. The admin page lists many properties, so each cell keeps its own.
 */
export function commissionBookingColumns(
    services: EnumOption[],
    {
        withProperty,
        currency = null,
    }: { withProperty: boolean; currency?: Currency },
) {
    const moneyHeader = (label: string) =>
        currency ? `${label} (${currency.code})` : label;
    const plain = currency !== null;

    return [
        ...(withProperty
            ? [
                  column.accessor('propertyName', {
                      header: 'Property',
                      enableSorting: false,
                  }),
              ]
            : []),
        column.accessor('bookingReference', {
            header: 'Booking',
            enableSorting: false,
            cell: (info) => (
                <span className="tabular-nums">{info.getValue()}</span>
            ),
        }),
        column.accessor('sourceName', {
            header: 'Source',
            enableSorting: false,
        }),
        column.accessor('bookedAt', {
            header: 'Booked',
            enableSorting: false,
            cell: (info) => (
                <span className="whitespace-nowrap tabular-nums">
                    {info.getValue()}
                </span>
            ),
        }),
        column.accessor('checkIn', {
            header: 'Check-in',
            enableSorting: false,
            cell: (info) => <DateText date={info.getValue()} />,
        }),
        column.accessor('checkOut', {
            header: 'Check-out',
            enableSorting: false,
            cell: (info) => <DateText date={info.getValue()} />,
        }),
        column.accessor('guestName', {
            header: 'Guest',
            enableSorting: false,
        }),
        column.accessor('value', {
            id: 'value',
            header: moneyHeader('Value'),
            enableSorting: false,
            cell: (info) => (
                <MoneyText
                    amount={info.getValue()}
                    currency={info.row.original.currency}
                    plain={plain}
                />
            ),
        }),
        ...services.map((service) =>
            column.accessor((row) => row.commissions[service.value], {
                id: service.value,
                header: moneyHeader(`${service.label} commission`),
                enableSorting: false,
                cell: (info) => (
                    <MoneyText
                        amount={info.getValue() ?? '0.00'}
                        currency={info.row.original.currency}
                        plain={plain}
                    />
                ),
            }),
        ),
        column.accessor('outstanding', {
            id: 'outstanding',
            header: moneyHeader('Outstanding to hotel'),
            enableSorting: false,
            cell: (info) => (
                <MoneyText
                    amount={info.getValue()}
                    currency={info.row.original.currency}
                    plain={plain}
                />
            ),
        }),
    ];
}

/** Right-aligns the money columns. */
export function commissionBookingAlign(services: EnumOption[]) {
    return {
        value: 'right' as const,
        outstanding: 'right' as const,
        ...Object.fromEntries(
            services.map((service) => [service.value, 'right' as const]),
        ),
    };
}
