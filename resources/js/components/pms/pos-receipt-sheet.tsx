import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
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
    const [failed, setFailed] = useState(false);
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        if (orderId === null) {
            return;
        }

        let cancelled = false;

        void fetch(receipt.url({ order: orderId }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                return response.json() as Promise<{
                    order: App.Data.Pos.PosOrderData;
                }>;
            })
            .then((body) => {
                if (!cancelled) {
                    setFailed(false);
                    setLoaded({ id: orderId, order: body.order });
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setFailed(true);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [orderId, attempt]);

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
                    {failed ? (
                        <div className="flex flex-col items-start gap-3 rounded-lg border p-4 text-sm">
                            <p className="text-destructive">
                                Could not load the POS receipt.
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    setFailed(false);
                                    setAttempt((count) => count + 1);
                                }}
                            >
                                Retry
                            </Button>
                        </div>
                    ) : order === null ? (
                        <Skeleton className="h-64 w-full" />
                    ) : (
                        <PosReceipt order={order} currency={currency} />
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}
