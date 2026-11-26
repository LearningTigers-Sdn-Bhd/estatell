import { router } from '@inertiajs/react';
import { AlertTriangle, BookOpen, Check, Clock, Printer } from 'lucide-react';
import type { ReactNode } from 'react';
import { StatusBadge } from '@/components/pms/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

export type KitchenLine = {
    id: number;
    name: string;
    variant: string;
    quantity: string;
    modifiers: string[];
    note: string | null;
    status: 'fired' | 'preparing' | 'ready' | 'served' | 'voided';
    course: number;
    seat: number | null;
    firedAt: string | null;
    readyAt: string | null;
    cookingGuide: string | null;
    combo: string | null;
    voided: boolean;
};

export type KitchenTicket = {
    id: number;
    label: string;
    type: 'table' | 'room' | 'walk_in';
    diningKind: string | null;
    firedAt: string | null;
    lines: KitchenLine[];
};

export type SlaTone = 'success' | 'warning' | 'destructive';

export type KitchenLineAction = {
    label: string;
    busy: boolean;
    disabled: boolean;
    onClick: () => void;
};

const COOKING_BADGE: App.Data.Shared.BadgeData = {
    value: 'preparing',
    label: 'Cooking',
    tone: 'info',
};

export function formatKitchenQuantity(quantity: string | number): string {
    const num = Number(quantity);

    if (Number.isNaN(num)) {
        return String(quantity);
    }

    return Number.isInteger(num)
        ? String(num)
        : num.toFixed(2).replace(/\.?0+$/, '');
}

/** SLA urgency by ticket age: success before warn, warning before alert, then destructive. */
export function slaTone(
    firedAt: string | null,
    now: number,
    warnAfterMinutes: number,
    alertAfterMinutes: number,
): SlaTone {
    if (firedAt === null) {
        return 'success';
    }

    const minutes = (now - new Date(firedAt).getTime()) / 60000;

    if (minutes >= alertAfterMinutes) {
        return 'destructive';
    }

    return minutes >= warnAfterMinutes ? 'warning' : 'success';
}

export function ageLabel(firedAt: string | null, now: number): string {
    if (firedAt === null) {
        return '';
    }

    const totalSeconds = Math.max(
        0,
        Math.floor((now - new Date(firedAt).getTime()) / 1000),
    );
    const totalMinutes = Math.floor(totalSeconds / 60);

    if (totalMinutes >= 60) {
        const hours = Math.floor(totalMinutes / 60);
        const mins = totalMinutes % 60;

        return `${hours}h ${String(mins).padStart(2, '0')}m`;
    }

    return `${totalMinutes}:${String(totalSeconds % 60).padStart(2, '0')}`;
}

export function KitchenTicketCard({
    ticket,
    tone,
    age,
    lineAction,
    footer,
    onPrint,
}: {
    ticket: KitchenTicket;
    tone: SlaTone;
    age: string;
    lineAction: (line: KitchenLine) => KitchenLineAction;
    footer?: ReactNode;
    onPrint?: () => void;
}) {
    const isOverdue = tone === 'destructive';
    const isLate = tone === 'warning';

    return (
        <article
            className={cn(
                'flex flex-col overflow-hidden rounded-lg border bg-card text-card-foreground',
                isOverdue && 'border-destructive',
                isLate && 'border-warning',
            )}
        >
            {isOverdue && (
                <div
                    className="h-1.5 w-full bg-destructive"
                    aria-hidden="true"
                />
            )}

            <div
                className={cn(
                    'flex items-center justify-between gap-2 px-4 py-3',
                    tone === 'success' &&
                        'bg-brand-forest text-primary-foreground',
                    isLate && 'bg-warning text-warning-foreground',
                    isOverdue &&
                        'border-b border-destructive/30 bg-card text-foreground',
                )}
            >
                <div className="flex min-w-0 flex-col">
                    <h2 className="truncate font-heading text-lg leading-tight font-bold tracking-tight tabular-nums">
                        {ticket.label}
                    </h2>
                    {ticket.diningKind !== null && (
                        <span
                            className={cn(
                                'text-xs font-medium',
                                tone === 'success' &&
                                    'text-primary-foreground/80',
                                isLate && 'text-warning-foreground/85',
                                isOverdue && 'text-muted-foreground',
                            )}
                        >
                            {ticket.diningKind}
                        </span>
                    )}
                </div>

                <div className="flex shrink-0 items-center gap-1">
                    {age !== '' && (
                        <span
                            className={cn(
                                'inline-flex items-center gap-1 rounded-md px-2 py-1 font-mono text-xs font-semibold tabular-nums',
                                tone === 'success' &&
                                    'bg-primary-foreground/15 text-primary-foreground',
                                isLate &&
                                    'bg-warning-foreground/15 text-warning-foreground',
                                isOverdue &&
                                    'border border-destructive bg-card text-destructive',
                            )}
                            aria-label={
                                isOverdue
                                    ? `Overdue, ${age} elapsed`
                                    : isLate
                                      ? `Late, ${age} elapsed`
                                      : `${age} elapsed`
                            }
                        >
                            {isOverdue ? (
                                <AlertTriangle
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                            ) : (
                                <Clock
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                            )}
                            <span>{age}</span>
                            {isLate && <span className="font-sans">Late</span>}
                            {isOverdue && (
                                <span className="font-sans">Overdue</span>
                            )}
                        </span>
                    )}
                    {onPrint !== undefined && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className={cn(
                                'size-11',
                                tone === 'success' &&
                                    'text-primary-foreground hover:bg-primary-foreground/15 hover:text-primary-foreground',
                                isLate &&
                                    'text-warning-foreground hover:bg-warning-foreground/15 hover:text-warning-foreground',
                            )}
                            aria-label={`Print docket for ${ticket.label}`}
                            onClick={onPrint}
                        >
                            <Printer aria-hidden="true" />
                        </Button>
                    )}
                </div>
            </div>

            <ul role="list" className="flex flex-1 flex-col divide-y">
                {ticket.lines.map((line) => {
                    const action = lineAction(line);
                    const metaParts: string[] = [];

                    if (line.course > 1) {
                        metaParts.push(`Course ${line.course}`);
                    }

                    if (line.seat !== null) {
                        metaParts.push(`Seat ${line.seat}`);
                    }

                    const isCooking = line.status === 'preparing';

                    const content = (
                        <div className="flex w-full items-start gap-3">
                            <span
                                className={cn(
                                    'w-8 shrink-0 font-heading text-lg leading-snug font-bold tabular-nums',
                                    line.voided
                                        ? 'text-muted-foreground'
                                        : 'text-foreground',
                                )}
                            >
                                {formatKitchenQuantity(line.quantity)}×
                            </span>
                            <div
                                className={cn(
                                    'flex min-w-0 flex-1 flex-col gap-1',
                                    line.voided &&
                                        'text-muted-foreground line-through',
                                )}
                            >
                                <span className="text-base leading-snug font-semibold text-foreground">
                                    {line.name}
                                    {line.variant !== '' &&
                                        line.variant !== 'Regular' && (
                                            <span className="font-normal text-muted-foreground">
                                                {' '}
                                                · {line.variant}
                                            </span>
                                        )}
                                    {line.voided && ' (VOID)'}
                                </span>
                                {(isCooking ||
                                    metaParts.length > 0 ||
                                    line.combo !== null) && (
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        {isCooking && !line.voided && (
                                            <StatusBadge
                                                status={COOKING_BADGE}
                                            />
                                        )}
                                        {metaParts.length > 0 && (
                                            <span className="text-xs font-medium text-muted-foreground tabular-nums">
                                                {metaParts.join(' · ')}
                                            </span>
                                        )}
                                        {line.combo !== null && (
                                            <Badge variant="secondary">
                                                Combo: {line.combo}
                                            </Badge>
                                        )}
                                    </div>
                                )}
                                {line.modifiers.length > 0 && (
                                    <span className="text-sm text-muted-foreground">
                                        {line.modifiers.join(', ')}
                                    </span>
                                )}
                                {line.note !== null && (
                                    <span className="mt-0.5 rounded-md border border-warning/40 bg-warning/10 px-2 py-1 text-sm font-medium text-warning-text">
                                        Note: {line.note}
                                    </span>
                                )}
                            </div>
                            {!line.voided && (
                                <span
                                    className={cn(
                                        'inline-flex shrink-0 items-center gap-1 rounded-md px-2.5 py-1 text-xs font-semibold',
                                        isCooking
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-muted text-foreground',
                                    )}
                                >
                                    {action.busy ? (
                                        <Spinner className="size-3.5" />
                                    ) : isCooking ? (
                                        <Check
                                            className="size-3.5"
                                            aria-hidden="true"
                                        />
                                    ) : null}
                                    {action.label}
                                </span>
                            )}
                        </div>
                    );

                    return (
                        <li
                            key={line.id}
                            className={cn(
                                'flex flex-col',
                                isCooking && !line.voided && 'bg-primary/5',
                            )}
                        >
                            {line.voided ? (
                                <div className="px-4 py-3.5">{content}</div>
                            ) : (
                                <button
                                    type="button"
                                    disabled={action.disabled}
                                    onClick={action.onClick}
                                    className="min-h-14 w-full px-4 py-3.5 text-left transition-colors hover:bg-muted/60 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-foreground disabled:opacity-60"
                                >
                                    {content}
                                </button>
                            )}

                            {line.cookingGuide !== null && !line.voided && (
                                <div className="px-4 pb-2.5 pl-15">
                                    <Popover>
                                        <PopoverTrigger
                                            render={
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="min-h-11 px-2 text-muted-foreground hover:text-foreground"
                                                />
                                            }
                                        >
                                            <BookOpen
                                                data-icon="inline-start"
                                                aria-hidden="true"
                                            />
                                            Cooking guide
                                        </PopoverTrigger>
                                        <PopoverContent align="start">
                                            <PopoverHeader>
                                                <PopoverTitle>
                                                    {line.name}
                                                </PopoverTitle>
                                            </PopoverHeader>
                                            <p className="whitespace-pre-line text-muted-foreground">
                                                {line.cookingGuide}
                                            </p>
                                        </PopoverContent>
                                    </Popover>
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>

            {footer && <div className="border-t bg-muted/30 p-3">{footer}</div>}
        </article>
    );
}

export function lineStatusLabel(status: KitchenLine['status']): string {
    return {
        fired: 'Sent',
        preparing: 'Preparing',
        ready: 'Ready',
        served: 'Served',
        voided: 'Voided',
    }[status];
}

type KitchenRequestCallbacks = {
    onStart?: () => void;
    onFinish?: () => void;
};

/** Post to a kitchen route while keeping scroll position; the server redirect reloads tickets. */
export function postKitchen(
    url: string,
    data: Record<string, string> = {},
    callbacks?: KitchenRequestCallbacks,
) {
    router.post(url, data, {
        preserveScroll: true,
        onStart: callbacks?.onStart,
        onFinish: callbacks?.onFinish,
    });
}

export function patchKitchen(
    url: string,
    data: Record<string, string>,
    callbacks?: KitchenRequestCallbacks,
) {
    router.patch(url, data, {
        preserveScroll: true,
        onStart: callbacks?.onStart,
        onFinish: callbacks?.onFinish,
    });
}
