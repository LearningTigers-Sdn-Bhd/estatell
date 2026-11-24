import { Head, Link } from '@inertiajs/react';
import { FileText, Printer, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { MoneyText } from '@/components/pms/money-text';
import { PosEInvoiceDialog } from '@/components/pms/pos/pos-einvoice-dialog';
import { PosRefundDialog } from '@/components/pms/pos/pos-refund-dialog';
import { PosReceipt } from '@/components/pms/pos-receipt';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index, refund, show } from '@/routes/finance/pos-orders';

type Props = {
    order: App.Data.Pos.PosOrderData;
    folioLinks: {
        id: number;
        folioId: number;
        grossAmount: string;
        refundedAmount: string;
    }[];
    refunds: { id: number; orderNumber: string | null; grandTotal: string }[];
    can: { refund: boolean; einvoice: boolean };
    currency: App.Data.Property.CurrencyData | null;
};

export default function PosOrdersShow({
    order,
    folioLinks,
    refunds,
    can,
    currency,
}: Props) {
    const [refunding, setRefunding] = useState(false);
    const [invoicing, setInvoicing] = useState(false);
    const settledSale =
        order.orderType === 'sale' && order.status === 'settled';
    const walkInShare =
        Number(order.grandTotal) - Number(order.folioPostedAmount);

    return (
        <>
            <Head title={`Receipt ${order.orderNumber ?? ''}`} />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={`Receipt ${order.orderNumber ?? ''}`}
                    description={`${order.outletName} · ${order.businessDate ?? ''}`}
                    back={{ href: index(), label: 'POS receipts' }}
                    badge={
                        <Badge variant="secondary">{order.statusLabel}</Badge>
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
                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
                    <Card>
                        <CardContent className="pt-6">
                            <PosReceipt order={order} currency={currency} />
                        </CardContent>
                    </Card>
                    <div className="flex flex-col gap-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>Links</CardTitle>
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
                                        Folio line{' '}
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
