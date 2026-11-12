import { Head } from '@inertiajs/react';
import {
    Banknote,
    CalendarClock,
    Coins,
    FileImage,
    HandCoins,
    ReceiptText,
    ScrollText,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { PortalInvoicesTable } from '@/components/pms/portal/portal-invoices-table';
import { PortalPayDialog } from '@/components/pms/portal/portal-pay-dialog';
import { PortalPaymentsTable } from '@/components/pms/portal/portal-payments-table';
import { PortalProofsTable } from '@/components/pms/portal/portal-proofs-table';
import { PortalStatTile } from '@/components/pms/portal/portal-stat-tile';
import { PortalStatementSection } from '@/components/pms/portal/portal-statement';
import { TabLinks } from '@/components/pms/tab-links';
import { Button } from '@/components/ui/button';
import { statements } from '@/routes/portal';
import {
    invoices as invoicesTab,
    payments as paymentsTab,
    slips as slipsTab,
} from '@/routes/portal/statements';
import { store as storeSlip } from '@/routes/portal/statements/payment-slips';

type Tab = 'statement' | 'invoices' | 'payments' | 'slips';

/**
 * The company's statements at the property: the summary and Send payment above link tabs (Statement, Invoices,
 * Payments, Slips). Each tab has its own URL and the server sends only that tab's list.
 */
export default function PortalStatements({
    tab,
    propertyCode,
    currency,
    summary,
    slipsWaiting,
    canSendSlip,
    bankAccounts,
    statement,
    invoices,
    payments,
    paymentSlips,
}: {
    tab: Tab;
    propertyCode: string;
    currency: App.Data.Property.CurrencyData | null;
    summary: App.Data.Portal.PortalAccountSummaryData;
    slipsWaiting: number;
    canSendSlip: boolean;
    bankAccounts: App.Data.Catalogues.BankAccountData[];
    statement?: App.Data.CityLedger.StatementData;
    invoices?: Illuminate.LengthAwarePaginator<
        number,
        App.Data.CityLedger.ArInvoiceRowData
    >;
    payments?: Illuminate.LengthAwarePaginator<
        number,
        App.Data.Portal.PortalPaymentRowData
    >;
    paymentSlips?: App.Data.Payments.PaymentProofData[];
}) {
    const [paying, setPaying] = useState(false);
    const key = { property: propertyCode };
    const money = (amount: string) => (
        <MoneyText amount={amount} currency={currency} />
    );
    const asOf = (
        <>
            As at <DateText date={summary.asOf} />
        </>
    );

    const tabs = [
        {
            value: 'statement',
            label: 'Statement',
            icon: ScrollText,
            href: statements.url(key),
        },
        {
            value: 'invoices',
            label: 'Invoices',
            icon: ReceiptText,
            href: invoicesTab.url(key),
        },
        {
            value: 'payments',
            label: 'Payments',
            icon: HandCoins,
            href: paymentsTab.url(key),
        },
        {
            value: 'slips',
            label: (
                <>
                    Slips
                    {slipsWaiting > 0 && (
                        <span className="text-muted-foreground tabular-nums">
                            {slipsWaiting}
                        </span>
                    )}
                </>
            ),
            icon: FileImage,
            href: slipsTab.url(key),
        },
    ];

    return (
        <>
            <Head title="Statements" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Statements"
                    description="Your statement of account, invoices and payments at this property."
                    actions={
                        canSendSlip && (
                            <Button onClick={() => setPaying(true)}>
                                <Banknote data-icon="inline-start" />
                                Send payment
                            </Button>
                        )
                    }
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <PortalStatTile
                        title="Balance"
                        icon={Wallet}
                        figure={money(summary.balance)}
                        note={asOf}
                    />
                    <PortalStatTile
                        title="Overdue"
                        icon={CalendarClock}
                        figure={money(summary.overdue)}
                        note={asOf}
                    />
                    <PortalStatTile
                        title="Credit"
                        icon={Coins}
                        figure={money(summary.credit)}
                        note="Payments not used for an invoice yet"
                    />
                </div>

                <TabLinks
                    tabs={tabs}
                    activeTab={tab}
                    panelWidth="full"
                    className="flex flex-col gap-4"
                >
                    {tab === 'statement' && statement && (
                        <PortalStatementSection
                            propertyCode={propertyCode}
                            statement={statement}
                            currency={currency}
                        />
                    )}
                    {tab === 'invoices' && invoices && (
                        <PortalInvoicesTable
                            propertyCode={propertyCode}
                            invoices={invoices}
                            currency={currency}
                        />
                    )}
                    {tab === 'payments' && payments && (
                        <PortalPaymentsTable
                            propertyCode={propertyCode}
                            payments={payments}
                            currency={currency}
                        />
                    )}
                    {tab === 'slips' && paymentSlips && (
                        <PortalProofsTable
                            proofs={paymentSlips}
                            currency={currency}
                            showFor
                            emptyText="No slips sent yet"
                        />
                    )}
                </TabLinks>
            </div>

            <PortalPayDialog
                open={paying}
                onOpenChange={setPaying}
                title="Send a payment"
                action={storeSlip.url(key)}
                figureLabel="Balance"
                figure={summary.balance}
                note="Pay any amount. We use it for your oldest invoices first and keep the rest as credit."
                bankAccounts={bankAccounts}
                currency={currency}
            />
        </>
    );
}

PortalStatements.layout = (props: { propertyCode: string }) => ({
    breadcrumbs: [
        {
            title: 'Statements',
            href: statements({ property: props.propertyCode }),
        },
    ],
});
