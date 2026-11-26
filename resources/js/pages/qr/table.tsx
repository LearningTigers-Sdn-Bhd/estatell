import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { QrMenu } from '@/components/pms/qr/qr-menu';
import type { QrCategory, QrLine } from '@/components/pms/qr/qr-menu';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { orders as tableOrders } from '@/routes/qr/table';

type Props = {
    token: string;
    status: 'active' | 'revoked' | 'expired' | 'locked';
    property: string;
    logoUrl: string | null;
    outlet: string;
    table: string;
    currency: App.Data.Property.CurrencyData | null;
    allowRemark: boolean;
    serviceChargeRate: string | null;
    categories: QrCategory[];
};

const closedMessage: Record<Exclude<Props['status'], 'active'>, string> = {
    revoked:
        'This table code was replaced or the table is settled. Ask the team for a new one.',
    expired: 'This table code has expired. Ask the team for a new one.',
    locked: 'This code is locked. Ask the team for help.',
};

export default function QrTable({
    token,
    status,
    property,
    logoUrl,
    outlet,
    table,
    currency,
    allowRemark,
    serviceChargeRate,
    categories,
}: Props) {
    const [sending, setSending] = useState(false);

    return (
        <>
            <Head title={`${property} · ${table}`} />
            <header className="sticky top-0 z-10 flex items-center gap-3 border-b bg-background p-4">
                {logoUrl !== null && (
                    <img
                        src={logoUrl}
                        alt={property}
                        className="size-12 shrink-0 object-contain"
                    />
                )}
                <div className="flex min-w-0 flex-col">
                    <p className="text-sm text-muted-foreground">{outlet}</p>
                    <h1 className="text-xl font-semibold">Table {table}</h1>
                </div>
            </header>

            {status !== 'active' ? (
                <Card className="m-4">
                    <CardHeader>
                        <CardTitle>Ordering is closed</CardTitle>
                    </CardHeader>
                    <CardContent>{closedMessage[status]}</CardContent>
                </Card>
            ) : (
                <QrMenu
                    categories={categories}
                    currency={currency}
                    allowRemark={allowRemark}
                    serviceChargeRate={serviceChargeRate}
                    sending={sending}
                    onSend={(lines: QrLine[], clearCart: () => void) => {
                        setSending(true);
                        router.post(
                            tableOrders.url({ token }),
                            { lines },
                            {
                                onSuccess: clearCart,
                                onFinish: () => setSending(false),
                            },
                        );
                    }}
                />
            )}
        </>
    );
}
