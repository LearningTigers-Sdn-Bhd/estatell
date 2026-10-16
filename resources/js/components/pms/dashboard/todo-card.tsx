import { Link } from '@inertiajs/react';
import { CircleCheck, ClipboardList } from 'lucide-react';
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
            <CardContent className="flex min-h-0 flex-1 flex-col">
                {todo.rows.length === 0 ? (
                    <div className="flex flex-1 items-center justify-center">
                        <EmptyState
                            icon={CircleCheck}
                            title="Nothing left for today"
                            description="All arrivals are in and all departures are out."
                        />
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-lg border">
                        <ul className="divide-y xl:max-h-112 xl:overflow-y-auto">
                            {todo.rows.map((row) => (
                                <li key={row.key}>
                                    <div className="flex flex-wrap items-center justify-between gap-3 p-4 md:flex-nowrap">
                                        <div className="flex min-w-0 flex-1 flex-col gap-2 md:flex-row md:items-center md:gap-4">
                                            <div className="flex shrink-0 items-center gap-2 md:w-28 md:flex-col md:items-start">
                                                <DashboardBadge
                                                    label={row.label}
                                                    tone={
                                                        row.kind === 'overdue'
                                                            ? 'danger'
                                                            : row.kind ===
                                                                'arrival'
                                                              ? 'success'
                                                              : row.kind ===
                                                                  'departure'
                                                                ? 'warning'
                                                                : 'neutral'
                                                    }
                                                />
                                                {row.time && (
                                                    <span className="text-xs text-muted-foreground tabular-nums">
                                                        {row.time}
                                                    </span>
                                                )}
                                            </div>
                                            <p className="min-w-0 flex-1 truncate font-medium">
                                                {row.guest}
                                            </p>
                                            <p className="flex min-w-0 items-baseline text-sm whitespace-nowrap text-muted-foreground tabular-nums md:max-w-64 md:justify-end">
                                                <span className="min-w-0 truncate">
                                                    {row.detail}
                                                </span>
                                                <span className="shrink-0">
                                                    {' · '}
                                                    {row.reference}
                                                </span>
                                            </p>
                                        </div>
                                        {row.action.kind === 'dialog' ? (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={loadingKey !== null}
                                                focusableWhenDisabled
                                                onClick={() => onAction(row)}
                                            >
                                                {loadingKey === row.key && (
                                                    <Spinner />
                                                )}
                                                {row.action.label}
                                            </Button>
                                        ) : (
                                            <Link
                                                href={row.action.url}
                                                className={buttonVariants({
                                                    variant: 'outline',
                                                    size: 'sm',
                                                })}
                                            >
                                                {row.action.label}
                                            </Link>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
