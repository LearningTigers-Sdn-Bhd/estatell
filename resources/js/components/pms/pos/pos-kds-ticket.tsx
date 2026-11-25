import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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

const toneClass: Record<SlaTone, string> = {
    success: 'border-success text-success',
    warning: 'border-warning text-warning',
    destructive: 'border-destructive text-destructive',
};

export function KitchenTicketCard({
    ticket,
    tone,
    age,
    actions,
    footer,
}: {
    ticket: KitchenTicket;
    tone: SlaTone;
    age: string;
    actions: (line: KitchenLine) => ReactNode;
    footer?: ReactNode;
}) {
    return (
        <Card className={cn('border-2', toneClass[tone])}>
            <CardHeader className="flex flex-row items-center justify-between gap-2">
                <CardTitle className="text-lg tabular-nums">
                    {ticket.label}
                </CardTitle>
                <div className="flex items-center gap-2">
                    {ticket.diningKind !== null && (
                        <Badge variant="secondary">{ticket.diningKind}</Badge>
                    )}
                    <span className="font-mono text-sm tabular-nums">
                        {age}
                    </span>
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
                {ticket.lines.map((line) => (
                    <div
                        key={line.id}
                        className="flex items-start justify-between gap-2"
                    >
                        <div
                            className={cn(
                                'flex min-w-0 flex-col',
                                line.voided &&
                                    'text-muted-foreground line-through',
                            )}
                        >
                            <span className="font-medium">
                                {line.quantity}× {line.name}
                                {line.voided && ' — VOID'}
                            </span>
                            {line.modifiers.length > 0 && (
                                <span className="text-sm text-muted-foreground">
                                    {line.modifiers.join(', ')}
                                </span>
                            )}
                            {line.combo !== null && (
                                <span className="text-sm text-muted-foreground">
                                    [COMBO: {line.combo}]
                                </span>
                            )}
                            {line.note !== null && (
                                <span className="text-sm">{line.note}</span>
                            )}
                        </div>
                        {!line.voided && actions(line)}
                    </div>
                ))}
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
