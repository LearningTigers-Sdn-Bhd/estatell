import { Link } from '@inertiajs/react';
import { ArrowRight, CircleCheck, ClipboardList } from 'lucide-react';
import { cn } from 'cn';
import { EmptyState } from '@/components/pms/empty-state';
import { DateText } from '@/components/pms/date-text';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { arrivals } from '@/routes/reports';

export function TodoSkeleton() {
    return (
        <Card className="min-h-80">
            <CardHeader>
                <Skeleton className="h-5 w-36" />
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
            <CardHeader className="flex flex-row items-center justify-between gap-3">
                <CardTitle className="flex items-center gap-2">
                    <ClipboardList
                        aria-hidden
                        className="size-4 text-muted-foreground"
                    />
                    To do today
                </CardTitle>
                <span className="text-sm text-muted-foreground tabular-nums">
                    {todo.total} {todo.total === 1 ? 'item' : 'items'}
                </span>
            </CardHeader>
            <CardContent className="flex-1 lg:max-h-112 lg:overflow-y-auto">
                {todo.rows.length === 0 ? (
                    <EmptyState
                        icon={CircleCheck}
                        title="Nothing left for today"
                        description="All arrivals are in and all departures are out."
                    />
                ) : (
                    <ul>
                        {todo.rows.map((row, index) => (
                            <li key={row.key}>
                                {index > 0 && <Separator />}
                                <div className="flex flex-wrap items-center justify-between gap-3 py-4">
                                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                                        <p
                                            className={cn(
                                                'text-xs font-medium',
                                                row.kind === 'overdue'
                                                    ? 'text-warning'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {row.label}
                                            {row.time && (
                                                <span className="ml-2 tabular-nums">
                                                    {row.time}
                                                </span>
                                            )}
                                        </p>
                                        <p className="truncate font-medium">
                                            {row.guest}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {row.detail} · {row.reference}
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
                )}
            </CardContent>
            <CardFooter className="justify-between gap-3">
                <span className="text-xs text-muted-foreground tabular-nums">
                    {todo.total > todo.rows.length ? (
                        `${todo.rows.length} of ${todo.total} shown`
                    ) : (
                        <DateText date={todo.date} />
                    )}
                </span>
                <Link
                    href={arrivals.url(undefined, {
                        query: { from: todo.date, to: todo.date },
                    })}
                    className={buttonVariants({ variant: 'ghost', size: 'sm' })}
                >
                    See all arrivals
                    <ArrowRight data-icon="inline-end" />
                </Link>
            </CardFooter>
        </Card>
    );
}
