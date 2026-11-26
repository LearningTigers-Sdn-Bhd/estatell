import { router } from '@inertiajs/react';
import { BookOpen, Clock, Printer } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Popover,
    PopoverContent,
    PopoverHeader,
    PopoverTitle,
    PopoverTrigger,
} from '@/components/ui/popover';
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

export function formatKitchenQuantity(quantity: string | number): string {
    const num = Number(quantity);

    if (Number.isNaN(num)) {
        return String(quantity);
    }

    return Number.isInteger(num)
        ? String(num)
        : num.toFixed(2).replace(/\.?0+$/, '');
}

/** The colour of a ticket by its age: success until the warn time, warning until the alert time, then destructive. */
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

    const seconds = Math.max(
        0,
        Math.floor((now - new Date(firedAt).getTime()) / 1000),
    );

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

const cardBorderClass: Record<SlaTone, string> = {
    success: 'border-success/70',
    warning: 'border-warning',
    destructive: 'border-destructive',
};

const headerToneClass: Record<SlaTone, string> = {
    success: 'border-b border-success/30 bg-success/10',
    warning: 'border-b border-warning/40 bg-warning/15',
    destructive: 'border-b border-destructive/40 bg-destructive/10',
};

const ageBadgeClass: Record<SlaTone, string> = {
    success: 'bg-success text-success-foreground',
    warning: 'bg-warning text-warning-foreground',
    destructive:
        'border border-destructive/50 bg-destructive/15 text-destructive',
};

export function KitchenTicketCard({
    ticket,
    tone,
    age,
    actions,
    footer,
    onPrint,
}: {
    ticket: KitchenTicket;
    tone: SlaTone;
    age: string;
    actions: (line: KitchenLine) => ReactNode;
    footer?: ReactNode;
    onPrint?: () => void;
}) {
    return (
        <Card
            className={cn(
                'flex flex-col overflow-hidden border-2 bg-card text-card-foreground',
                cardBorderClass[tone],
            )}
        >
            <CardHeader
                className={cn(
                    'flex flex-row items-center justify-between gap-2 px-4 py-3',
                    headerToneClass[tone],
                )}
            >
                <div className="flex min-w-0 flex-col gap-0.5">
                    <CardTitle className="truncate font-heading text-lg text-foreground tabular-nums">
                        {ticket.label}
                    </CardTitle>
                    {ticket.diningKind !== null && (
                        <span className="text-xs text-muted-foreground">
                            {ticket.diningKind}
                        </span>
                    )}
                </div>
                <div className="flex shrink-0 items-center gap-1.5">
                    {age !== '' && (
                        <span
                            className={cn(
                                'inline-flex items-center gap-1 rounded-md px-2 py-1 font-mono text-xs font-semibold tabular-nums',
                                ageBadgeClass[tone],
                            )}
                        >
                            <Clock className="size-3" aria-hidden="true" />
                            {age}
                        </span>
                    )}
                    {onPrint !== undefined && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-11"
                            aria-label={`Print docket for ${ticket.label}`}
                            onClick={onPrint}
                        >
                            <Printer aria-hidden="true" />
                        </Button>
                    )}
                </div>
            </CardHeader>
            <CardContent className="flex flex-1 flex-col gap-3 p-4">
                <ul role="list" className="flex flex-1 flex-col gap-3">
                    {ticket.lines.map((line) => (
                        <li
                            key={line.id}
                            className="flex items-start justify-between gap-2 border-b pb-3 last:border-b-0 last:pb-0"
                        >
                            <div
                                className={cn(
                                    'flex min-w-0 flex-col gap-1',
                                    line.voided &&
                                        'text-muted-foreground line-through',
                                )}
                            >
                                <div className="flex items-start gap-2">
                                    <span className="inline-flex min-w-8 shrink-0 items-center justify-center rounded-md bg-muted px-1.5 py-0.5 text-sm font-semibold text-foreground tabular-nums">
                                        {formatKitchenQuantity(line.quantity)}×
                                    </span>
                                    <span className="text-base leading-snug font-medium text-foreground">
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
                                </div>
                                {line.modifiers.length > 0 && (
                                    <span className="text-sm text-muted-foreground">
                                        {line.modifiers.join(', ')}
                                    </span>
                                )}
                                {line.combo !== null && (
                                    <Badge
                                        variant="secondary"
                                        className="w-fit"
                                    >
                                        Combo: {line.combo}
                                    </Badge>
                                )}
                                {line.note !== null && (
                                    <span className="rounded-md border border-warning/50 bg-warning/10 px-2 py-1 text-sm font-medium text-warning-text">
                                        Note: {line.note}
                                    </span>
                                )}
                                {line.cookingGuide !== null && !line.voided && (
                                    <Popover>
                                        <PopoverTrigger
                                            render={
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="mt-0.5 min-h-11 w-fit px-2.5"
                                                />
                                            }
                                        >
                                            <BookOpen
                                                data-icon="inline-start"
                                                aria-hidden="true"
                                            />
                                            Guide
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
                                )}
                            </div>
                            {!line.voided && actions(line)}
                        </li>
                    ))}
                </ul>
                {footer}
            </CardContent>
        </Card>
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

/** Post to a kitchen route, keeping the scroll, and let the server's redirect reload the screen. */
export function postKitchen(url: string, data: Record<string, string> = {}) {
    router.post(url, data, { preserveScroll: true });
}

export function patchKitchen(url: string, data: Record<string, string>) {
    router.patch(url, data, { preserveScroll: true });
}
