import { useEffect, useState } from 'react';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { receipt } from '@/routes/finance/pos-orders';
import { PosReceipt } from '@/components/pms/pos-receipt';

/**
 * A read-only look at the POS receipt behind a room charge on the folio. The folio ledger stays one line; the items are
 * here. It loads the receipt when it opens.
 */
type PosCurrency = App.Data.Property.CurrencyData | null;

export function PosFolioReceiptSheet({
    orderId,
    currency,
    onClose,
}: {
    orderId: number | null;
    currency: PosCurrency;
    onClose: () => void;
}) {
    const [loaded, setLoaded] = useState<{
        id: number;
        order: App.Data.Pos.PosOrderData;
    } | null>(null);

    useEffect(() => {
        if (orderId === null) {
            return;
        }

        let cancelled = false;

        void fetch(receipt.url({ order: orderId }), {
            headers: { Accept: 'application/json' },
        })
            .then(
                (response) =>
                    response.json() as Promise<{
                        order: App.Data.Pos.PosOrderData;
                    }>,
            )
            .then(
                (body) =>
                    !cancelled && setLoaded({ id: orderId, order: body.order }),
            );

        return () => {
            cancelled = true;
        };
    }, [orderId]);

    const order =
        loaded !== null && loaded.id === orderId ? loaded.order : null;

    return (
        <Sheet
            open={orderId !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <SheetContent>
                <SheetHeader>
                    <SheetTitle>POS receipt</SheetTitle>
                    <SheetDescription>
                        What the guest bought. Refunds are made in the POS, not
                        here.
                    </SheetDescription>
                </SheetHeader>
                <div className="overflow-y-auto p-4">
                    {order === null ? (
                        <Skeleton className="h-64 w-full" />
                    ) : (
                        <PosReceipt order={order} currency={currency} />
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}
