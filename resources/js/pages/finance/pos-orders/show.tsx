import { Head, Link } from '@inertiajs/react';
import { BedDouble, FileText, Printer, Undo2, Wallet } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { StatCard } from '@/components/pms/stat-card';
import { StatusBadge } from '@/components/pms/status-badge';
import { MoneyText } from '@/components/pms/money-text';
import { PosChangeTenderDialog } from '@/components/pms/pos/pos-change-tender-dialog';
import { PosEInvoiceDialog } from '@/components/pms/pos/pos-einvoice-dialog';
import { PosRefundDialog } from '@/components/pms/pos/pos-refund-dialog';
import { PosReceipt } from '@/components/pms/pos-receipt';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    changePayment,
    index,
    refund,
    show,
} from '@/routes/finance/pos-orders';
import { folio as reservationFolio } from '@/routes/reservations';

type Props = {
    order: App.Data.Pos.PosOrderData;
    folioLinks: {
        id: number;
        folioId: number;
        reservationId: number | null;
        grossAmount: string;
        refundedAmount: string;
    }[];
    refunds: { id: number; orderNumber: string | null; grandTotal: string }[];
    can: { refund: boolean; einvoice: boolean };
    tenders: { value: string; label: string }[];
    currency: App.Data.Property.CurrencyData | null;
};

export default function PosOrdersShow({
    order,
    folioLinks,
    refunds,
    can,
    tenders,
    currency,
}: Props) {
    const [refunding, setRefunding] = useState(false);
    const [invoicing, setInvoicing] = useState(false);
    const [swapping, setSwapping] = useState(false);
    const settledSale =
        order.orderType === 'sale' && order.status === 'settled';
    const walkInShare =
        Number(order.grandTotal) - Number(order.folioPostedAmount);

    return (
        <>
            <Head title={`Receipt ${order.orderNumber ?? ''}`} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={`Receipt ${order.orderNumber ?? ''}`}
                    description={`${order.outletName} · ${order.businessDate ?? ''}`}
                    back={{ href: index(), label: 'POS receipts' }}
                    badge={
                        <StatusBadge
                            status={{
                                value: order.status,
                                label: order.statusLabel,
                                tone:
                                    order.status === 'voided'
                                        ? 'danger'
                                        : 'success',
                            }}
                        />
                    }
                    actions={
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                onClick={() => window.print()}
                            >
                                <Printer data-icon="inline-start" />
                                Reprint
                            </Button>
                            {can.refund && settledSale && (
                                <Button
                                    variant="outline"
                                    onClick={() => setRefunding(true)}
                                >
                                    <Undo2 data-icon="inline-start" />
                                    Refund
                                </Button>
                            )}
                            {can.refund && settledSale && (
                                <Button
                                    variant="outline"
                                    onClick={() => setSwapping(true)}
                                >
                                    Change tender
                                </Button>
                            )}
                            {can.einvoice &&
                                settledSale &&
                                order.eInvoiceSubmissionId === null &&
                                walkInShare > 0 && (
                                    <Button onClick={() => setInvoicing(true)}>
                                        <FileText data-icon="inline-start" />
                                        Issue e-invoice
                                    </Button>
                                )}
                        </div>
                    }
                />
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        icon={Wallet}
                        label={
                            order.orderType === 'refund' ? 'Returned' : 'Total'
                        }
                        value={
                            <MoneyText
                                amount={order.grandTotal}
                                currency={currency}
                            />
                        }
                        hint="With service charge and tax"
                    />
                    <StatCard
                        icon={BedDouble}
                        label="Charged to a room"
                        value={
                            <MoneyText
                                amount={order.folioPostedAmount}
                                currency={currency}
                            />
                        }
                        hint={
                            Number(order.folioPostedAmount) > 0
                                ? 'Posted to a folio'
                                : 'Nothing on a folio'
                        }
                    />
                    <StatCard
                        icon={Undo2}
                        label="Refunds"
                        value={refunds.length}
                        hint={
                            refunds.length === 0
                                ? 'None against this receipt'
                                : 'See linked records'
                        }
                    />
                    <StatCard
                        icon={FileText}
                        label="E-invoice"
                        value={
                            order.eInvoiceSubmissionId === null
                                ? 'Not filed'
                                : 'Filed'
                        }
                        hint="LHDN submission"
                    />
                </div>
                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
                    <Card>
                        <CardContent className="pt-6">
                            <PosReceipt order={order} currency={currency} />
                        </CardContent>
                    </Card>
                    <div className="flex flex-col gap-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>Linked records</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2 text-sm">
                                {Number(order.folioPostedAmount) > 0 && (
                                    <p>
                                        Charged to a room:{' '}
                                        <MoneyText
                                            amount={order.folioPostedAmount}
                                            currency={currency}
                                        />
                                    </p>
                                )}
                                {folioLinks.map((link) => (
                                    <p
                                        key={link.id}
                                        className="text-muted-foreground"
                                    >
                                        {link.reservationId !== null ? (
                                            <Link
                                                className="font-medium text-foreground underline underline-offset-4"
                                                href={reservationFolio.url(
                                                    {
                                                        reservation:
                                                            link.reservationId,
                                                    },
                                                    {
                                                        query: {
                                                            folio: link.folioId,
                                                        },
                                                    },
                                                )}
                                            >
                                                Folio #{link.folioId}
                                            </Link>
                                        ) : (
                                            <span>Folio #{link.folioId}</span>
                                        )}{' '}
                                        ·{' '}
                                        <MoneyText
                                            amount={link.grossAmount}
                                            currency={currency}
                                        />
                                        {Number(link.refundedAmount) > 0 && (
                                            <>
                                                {' '}
                                                · refunded{' '}
                                                <MoneyText
                                                    amount={link.refundedAmount}
                                                    currency={currency}
                                                />
                                            </>
                                        )}
                                    </p>
                                ))}
                                <p>
                                    {order.eInvoiceSubmissionId === null
                                        ? 'Not on an e-invoice yet.'
                                        : 'On an e-invoice.'}
                                </p>
                                {order.refundForOrderNumber && (
                                    <Link
                                        className="underline"
                                        href={show.url({
                                            order: order.refundForOrderId ?? 0,
                                        })}
                                    >
                                        Refund of {order.refundForOrderNumber}
                                    </Link>
                                )}
                                {refunds.map((row) => (
                                    <Link
                                        key={row.id}
                                        className="underline"
                                        href={show.url({ order: row.id })}
                                    >
                                        Refund {row.orderNumber} ·{' '}
                                        <MoneyText
                                            amount={row.grandTotal}
                                            currency={currency}
                                        />
                                    </Link>
                                ))}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
            <PosRefundDialog
                open={refunding}
                onClose={() => setRefunding(false)}
                order={order}
                url={refund.url({ order: order.id })}
            />
            <PosChangeTenderDialog
                open={swapping}
                onClose={() => setSwapping(false)}
                order={order}
                tenders={tenders}
                url={changePayment.url({ order: order.id })}
            />
            <PosEInvoiceDialog
                open={invoicing}
                onClose={() => setInvoicing(false)}
                orderId={order.id}
            />
        </>
    );
}

PosOrdersShow.layout = {
    breadcrumbs: [{ title: 'POS receipts', href: index() }],
};
