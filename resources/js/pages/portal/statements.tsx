import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/pms/page-header';
import { PortalInvoicesTable } from '@/components/pms/portal/portal-invoices-table';
import { PortalPaymentsTable } from '@/components/pms/portal/portal-payments-table';
import { PortalStatementSection } from '@/components/pms/portal/portal-statement';
import { statements } from '@/routes/portal';

export default function PortalStatements({
    propertyCode,
    currency,
    statement,
    invoices,
    payments,
}: {
    propertyCode: string;
    currency: App.Data.Property.CurrencyData | null;
    statement: App.Data.CityLedger.StatementData;
    invoices: Illuminate.LengthAwarePaginator<
        number,
        App.Data.CityLedger.ArInvoiceRowData
    >;
    payments: Illuminate.LengthAwarePaginator<
        number,
        App.Data.Portal.PortalPaymentRowData
    >;
}) {
    return (
        <>
            <Head title="Statements" />

            <div className="flex flex-1 flex-col gap-8 p-4">
                <PageHeader
                    title="Statements"
                    description="Your statement of account, invoices and payments at this property."
                />

                <PortalStatementSection
                    propertyCode={propertyCode}
                    statement={statement}
                    currency={currency}
                />

                <section className="flex flex-col gap-3">
                    <h2 className="text-base font-medium">Invoices</h2>
                    <PortalInvoicesTable
                        propertyCode={propertyCode}
                        invoices={invoices}
                        currency={currency}
                    />
                </section>

                <section className="flex flex-col gap-3">
                    <h2 className="text-base font-medium">Payments</h2>
                    <PortalPaymentsTable
                        propertyCode={propertyCode}
                        payments={payments}
                        currency={currency}
                    />
                </section>
            </div>
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
