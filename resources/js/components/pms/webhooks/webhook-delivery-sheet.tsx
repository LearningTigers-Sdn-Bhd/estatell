import { router } from '@inertiajs/react';
import { Clock, Copy, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { toast } from '@/components/ui/toast';
import { retry } from '@/routes/admin/webhooks/deliveries';

type Delivery = App.Data.Webhooks.WebhookDeliveryData;

function Detail({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid grid-cols-3 gap-2 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="col-span-2 min-w-0 break-all">{children}</dd>
        </div>
    );
}

/**
 * One delivery: every try and the body that was sent, with Retry now on a refused or failed delivery. Read-only.
 */
export function WebhookDeliverySheet({
    delivery,
    onClose,
}: {
    delivery: Delivery | null;
    onClose: () => void;
}) {
    const [retrying, setRetrying] = useState(false);

    const copy = async () => {
        if (delivery) {
            await navigator.clipboard.writeText(delivery.payload);
            toast.add({ title: 'Payload copied.', type: 'success' });
        }
    };

    const retryNow = () => {
        if (!delivery) {
            return;
        }

        router.post(
            retry.url(delivery.row.id),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setRetrying(true),
                onFinish: () => setRetrying(false),
            },
        );
    };

    return (
        <Sheet
            open={delivery !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <SheetContent className="data-[side=right]:md:max-w-160">
                {delivery && (
                    <>
                        <SheetHeader>
                            <SheetTitle className="flex items-center gap-2">
                                <span className="font-mono">
                                    {delivery.row.event}
                                </span>
                                <StatusBadge status={delivery.row.status} />
                            </SheetTitle>
                            <SheetDescription>
                                {delivery.row.endpointName}
                                {delivery.row.isTest ? ' · Test event' : ''}
                            </SheetDescription>
                        </SheetHeader>

                        <div className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto px-4 pb-4">
                            <dl className="flex flex-col gap-2">
                                <Detail label="Event id">
                                    <span className="font-mono text-xs">
                                        {delivery.eventId}
                                    </span>
                                </Detail>
                                <Detail label="Created">
                                    <span className="tabular-nums">
                                        {delivery.row.createdAt}
                                    </span>
                                </Detail>
                                {delivery.deliveredAt && (
                                    <Detail label="Delivered">
                                        <span className="tabular-nums">
                                            {delivery.deliveredAt}
                                        </span>
                                    </Detail>
                                )}
                                {delivery.nextAttemptAt && (
                                    <Detail label="Next try">
                                        <span className="tabular-nums">
                                            {delivery.nextAttemptAt}
                                        </span>
                                    </Detail>
                                )}
                                {delivery.error && (
                                    <Detail label="Last error">
                                        {delivery.error}
                                    </Detail>
                                )}
                            </dl>

                            <section className="flex flex-col gap-2">
                                <h3 className="text-sm font-medium">Tries</h3>
                                <div className="overflow-hidden rounded-lg border">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>#</TableHead>
                                                <TableHead>Time</TableHead>
                                                <TableHead>Result</TableHead>
                                                <TableHead className="text-right">
                                                    ms
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {delivery.tries.length === 0 && (
                                                <TableRow className="hover:bg-transparent">
                                                    <TableCell colSpan={4}>
                                                        <EmptyState
                                                            icon={Clock}
                                                            title="Not tried yet"
                                                            description="The first try is queued."
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            )}
                                            {delivery.tries.map((attempt) => (
                                                <TableRow key={attempt.attempt}>
                                                    <TableCell className="tabular-nums">
                                                        {attempt.attempt}
                                                    </TableCell>
                                                    <TableCell className="tabular-nums">
                                                        {attempt.attemptedAt}
                                                    </TableCell>
                                                    <TableCell className="max-w-64 truncate">
                                                        {attempt.error ??
                                                            `HTTP ${attempt.responseStatus}`}
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {attempt.durationMs}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </section>

                            <section className="flex flex-col gap-2">
                                <div className="flex items-center justify-between gap-2">
                                    <h3 className="text-sm font-medium">
                                        Payload
                                    </h3>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={copy}
                                    >
                                        <Copy data-icon="inline-start" />
                                        Copy JSON
                                    </Button>
                                </div>
                                <pre className="overflow-x-auto rounded-lg border bg-muted p-3 font-mono text-xs">
                                    {delivery.payload}
                                </pre>
                            </section>
                        </div>

                        {delivery.row.canRetry && (
                            <SheetFooter>
                                <Button
                                    onClick={retryNow}
                                    disabled={retrying}
                                    focusableWhenDisabled
                                >
                                    {retrying ? (
                                        <Spinner data-icon="inline-start" />
                                    ) : (
                                        <RotateCcw data-icon="inline-start" />
                                    )}
                                    Retry now
                                </Button>
                            </SheetFooter>
                        )}
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}
