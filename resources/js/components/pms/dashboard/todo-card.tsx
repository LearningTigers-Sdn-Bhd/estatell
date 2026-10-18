import { Link } from '@inertiajs/react';
import {
    BedDouble,
    CalendarDays,
    CircleCheck,
    ClipboardList,
    Clock,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { actionIcon } from '@/components/pms/action-icons';
import { EmptyState } from '@/components/pms/empty-state';
import { DateText } from '@/components/pms/date-text';
import { Button, buttonVariants } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardAction,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { DashboardBadge } from '@/components/pms/dashboard/dashboard-badge';

export function TodoSkeleton() {
    return (
        <Card className="min-h-80 flex-1">
            <CardHeader>
                <div className="flex items-center gap-2">
                    <Skeleton className="size-5" />
                    <div className="flex flex-col gap-1">
                        <Skeleton className="h-5 w-36" />
                        <Skeleton className="h-4 w-24" />
                    </div>
                </div>
                <CardAction>
                    <Skeleton className="h-5 w-16 rounded-full" />
                </CardAction>
            </CardHeader>
            <CardContent className="flex flex-col gap-6">
                {[0, 1, 2, 3].map((key) => (
                    <div key={key} className="flex flex-col gap-2">
                        <Skeleton className="h-4 w-2/3" />
                        <Skeleton className="h-4 w-1/2" />
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

type Row = App.Data.Dashboard.DashboardTodoRowData;

const tones: Record<string, 'danger' | 'success' | 'warning' | 'neutral'> = {
    overdue: 'danger',
    arrival: 'success',
    departure: 'warning',
};

function Part({
    icon: Icon,
    children,
}: {
    icon?: LucideIcon;
    children: string;
}) {
    return (
        <span className="flex items-center gap-1 whitespace-nowrap">
            {Icon && <Icon aria-hidden className="size-3.5" />}
            {children}
        </span>
    );
}

/**
 * Line 1: the task badge and the guest. Line 2: time, day, room and reference as separate muted parts with icons.
 * A folio note has no icon.
 */
function TodoRowText({ row }: { row: Row }) {
    return (
        <div className="flex min-w-0 flex-1 flex-col gap-1">
            <div className="flex min-w-0 items-center gap-2">
                <DashboardBadge
                    label={row.label}
                    tone={tones[row.kind] ?? 'neutral'}
                />
                <p className="min-w-0 truncate font-medium">{row.guest}</p>
            </div>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground tabular-nums">
                {row.time && <Part icon={Clock}>{row.time}</Part>}
                {row.when && <Part icon={CalendarDays}>{row.when}</Part>}
                <Part icon={row.kind === 'folio' ? undefined : BedDouble}>
                    {row.detail}
                </Part>
                <Part>{row.reference}</Part>
            </div>
        </div>
    );
}

/** The row's button. It stretches over the whole row, so a click anywhere on the row runs it. */
function TodoRowAction({
    row,
    loadingKey,
    onAction,
}: {
    row: Row;
    loadingKey: string | null;
    onAction: (row: Row) => void;
}) {
    const Icon = actionIcon(row.action.icon);

    if (row.action.kind === 'dialog') {
        return (
            <Button
                variant="outline"
                size="sm"
                className="after:absolute after:inset-0"
                disabled={loadingKey !== null}
                focusableWhenDisabled
                onClick={() => onAction(row)}
            >
                {loadingKey === row.key ? (
                    <Spinner data-icon="inline-start" />
                ) : (
                    <Icon data-icon="inline-start" />
                )}
                {row.action.label}
            </Button>
        );
    }

    return (
        <Link
            href={row.action.url}
            className={buttonVariants({
                variant: 'outline',
                size: 'sm',
                className: 'after:absolute after:inset-0',
            })}
        >
            <Icon data-icon="inline-start" />
            {row.action.label}
        </Link>
    );
}

/**
 * Today's tasks as flat rows that run edge to edge inside the card. The row's action stretches over the whole row,
 * so a click anywhere on the row opens it.
 */
export function TodoCard({
    todo,
    loadingKey,
    onAction,
}: {
    todo: App.Data.Dashboard.DashboardTodoData;
    loadingKey: string | null;
    onAction: (row: App.Data.Dashboard.DashboardTodoRowData) => void;
}) {
    return (
        <Card className="flex-1">
            <CardHeader>
                <div className="flex items-center gap-2">
                    <ClipboardList
                        aria-hidden
                        className="size-5 shrink-0 text-muted-foreground"
                    />
                    <div className="flex min-w-0 flex-col gap-1">
                        <CardTitle>To do today</CardTitle>
                        <CardDescription>
                            <DateText date={todo.date} />
                        </CardDescription>
                    </div>
                </div>
                <CardAction>
                    <Badge variant="outline" className="tabular-nums">
                        {todo.total} {todo.total === 1 ? 'item' : 'items'}
                    </Badge>
                </CardAction>
            </CardHeader>
            <CardContent className="flex min-h-0 flex-1 flex-col px-0">
                {todo.rows.length === 0 ? (
                    <div className="flex flex-1 items-center justify-center px-(--card-spacing)">
                        <EmptyState
                            icon={CircleCheck}
                            title="Nothing left for today"
                            description="All arrivals are in and all departures are out."
                        />
                    </div>
                ) : (
                    <ul className="divide-y border-y xl:max-h-112 xl:overflow-y-auto">
                        {todo.rows.map((row) => (
                            <li
                                key={row.key}
                                className="relative flex items-center gap-3 px-(--card-spacing) py-3 transition-colors hover:bg-muted/50"
                            >
                                <TodoRowText row={row} />
                                <TodoRowAction
                                    row={row}
                                    loadingKey={loadingKey}
                                    onAction={onAction}
                                />
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
