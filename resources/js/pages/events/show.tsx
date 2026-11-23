import { Head } from '@inertiajs/react';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    EventShell,
    eventBreadcrumbs,
} from '@/components/pms/events/event-shell';
import type { EventShellProps } from '@/components/pms/events/event-shell';
import { FolioTab } from '@/components/pms/folio/folio-tab';
import type { VoucherOptions } from '@/components/pms/folio/print-voucher-dialog';
import { useSummaryHidden } from '@/components/pms/use-summary-hidden';

type Props = EventShellProps & {
    folio: App.Data.Folio.FolioData | null;
    chargeItems?: App.Data.Reservations.ChargeItemOptionData[];
    chargeCodes?: App.Data.Reservations.ChargeCodeOptionData[];
    folioDiscounts?: App.Data.Folio.FolioDiscountOptionData[];
    paymentOptions?: App.Data.Payments.PaymentFormOptionsData;
    creditInvoice?: App.Data.CityLedger.ArInvoiceData | null;
    voucher: VoucherOptions;
    currency: App.Data.Property.CurrencyData | null;
};

const NO_REASONS: EnumOption[] = [];

/**
 * Event page, Folio tab: the event folio in the same panel as a reservation folio, without routing, moving charges,
 * manual entry or e-invoicing.
 */
export default function EventShow({
    folio,
    chargeItems,
    chargeCodes,
    folioDiscounts,
    paymentOptions,
    creditInvoice,
    voucher,
    currency,
    ...props
}: Props) {
    const [summaryHidden, toggleSummary] = useSummaryHidden(
        'events.summary-hidden',
    );

    return (
        <>
            <Head title={props.event.name} />
            <EventShell
                {...props}
                activeTab="folio"
                hideSummary={summaryHidden}
            >
                <FolioTab
                    owner={{ kind: 'event', id: props.event.id }}
                    units={[]}
                    folio={folio}
                    chargeItems={chargeItems}
                    chargeCodes={chargeCodes}
                    folioDiscounts={folioDiscounts}
                    manualReasons={NO_REASONS}
                    paymentOptions={paymentOptions}
                    canViewCompany={props.canViewCompany}
                    companies={props.companies}
                    canManageRouting={false}
                    creditInvoice={creditInvoice}
                    manualLines={{}}
                    voucher={voucher}
                    summaryHidden={summaryHidden}
                    onToggleSummary={toggleSummary}
                    currency={currency}
                />
            </EventShell>
        </>
    );
}

EventShow.layout = (props: Props) => ({
    breadcrumbs: eventBreadcrumbs(props, 'folio'),
});
