import { Head, router } from '@inertiajs/react';
import {
    Check,
    Clock,
    Coffee,
    Moon,
    MoreHorizontal,
    Search,
    Sun,
    Sunrise,
    Undo2,
    Users,
    Utensils,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Kbd } from '@/components/ui/kbd';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
import { destroy, index, store } from '@/routes/restaurant/meal-check-in';

type Row = App.Data.Pos.MealCheckInRow;
type List = App.Data.Pos.MealCheckInListData;
type PeriodOption = { value: string; label: string };

const STATUS_BADGE: Record<string, App.Data.Shared.BadgeData> = {
    pending: { value: 'pending', label: 'Pending', tone: 'warning' },
    partial: { value: 'partial', label: 'Partial', tone: 'info' },
    redeemed: { value: 'redeemed', label: 'Checked in', tone: 'success' },
};

function periodIcon(label: string): LucideIcon {
    const lower = label.toLocaleLowerCase();

    if (lower.includes('breakfast') || lower.includes('morning')) {
        return Sunrise;
    }

    if (lower.includes('lunch') || lower.includes('brunch')) {
        return Sun;
    }

    if (lower.includes('tea') || lower.includes('coffee')) {
        return Coffee;
    }

    if (
        lower.includes('dinner') ||
        lower.includes('supper') ||
        lower.includes('evening')
    ) {
        return Moon;
    }

    return Clock;
}

/** Pick how many of the remaining guests arrived. */
function PartialPopover({
    row,
    disabled,
    onCheckIn,
    className,
}: {
    row: Row;
    disabled: boolean;
    onCheckIn: (row: Row, guests: number) => void;
    className?: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger
                render={
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className={className}
                        aria-label={`Partial check-in for room ${row.roomNumber}`}
                        disabled={disabled || row.remainingGuests < 2}
                    />
                }
            >
                Partial
            </PopoverTrigger>
            <PopoverContent align="end" className="flex w-60 flex-col gap-3">
                <div className="flex flex-col gap-0.5">
                    <span className="text-sm font-medium text-foreground">
                        Room {row.roomNumber} partial arrival
                    </span>
                    <span className="text-xs text-muted-foreground">
                        Choose how many of the {row.remainingGuests} remaining
                        guests arrived.
                    </span>
                </div>
                <div className="flex flex-wrap justify-end gap-1.5">
                    {Array.from(
                        { length: row.remainingGuests - 1 },
                        (_, position) => position + 1,
                    ).map((count) => (
                        <Button
                            key={count}
                            type="button"
                            size="sm"
                            variant="outline"
                            className="min-w-10 tabular-nums max-sm:min-h-11"
                            onClick={() => {
                                setOpen(false);
                                onCheckIn(row, count);
                            }}
                        >
                            {count}
                        </Button>
                    ))}
                </div>
            </PopoverContent>
        </Popover>
    );
}

/** Radial attendance indicator for the right-hand service summary rail. */
function AttendanceRing({
    checkedIn,
    totalEntitled,
    percent,
}: {
    checkedIn: number;
    totalEntitled: number;
    percent: number;
}) {
    const radius = 44;
    const circumference = 2 * Math.PI * radius;
    const offset = circumference - (percent / 100) * circumference;

    return (
        <div className="relative mx-auto flex size-32 items-center justify-center">
            <svg
                viewBox="0 0 112 112"
                className="size-full -rotate-90"
                role="img"
                aria-label={`${percent}% attendance: ${checkedIn} of ${totalEntitled} guests checked in`}
            >
                <circle
                    cx="56"
                    cy="56"
                    r={radius}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="9"
                    className="text-muted"
                />
                <circle
                    cx="56"
                    cy="56"
                    r={radius}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="9"
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    strokeDashoffset={offset}
                    className="text-primary transition-[stroke-dashoffset] duration-300 motion-reduce:transition-none"
                />
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center text-center">
                <span className="font-heading text-2xl leading-none font-bold tracking-tight text-foreground tabular-nums">
                    {percent}%
                </span>
                <span className="mt-1 text-xs text-muted-foreground tabular-nums">
                    {checkedIn} / {totalEntitled}
                </span>
            </div>
        </div>
    );
}

/** Visual seat dots alongside exact guest numbers so party arrival is scannable at a glance. */
function GuestSeatsIndicator({
    entitled,
    redeemed,
}: {
    entitled: number;
    redeemed: number;
}) {
    const showDots = entitled <= 8;

    return (
        <span className="inline-flex items-center gap-2 text-xs text-muted-foreground tabular-nums">
            {showDots && (
                <span
                    aria-hidden="true"
                    className="inline-flex items-center gap-1"
                >
                    {Array.from({ length: entitled }, (_, idx) => (
                        <span
                            key={idx}
                            className={cn(
                                'size-2 rounded-full',
                                idx < redeemed
                                    ? 'bg-success'
                                    : 'border border-muted-foreground/45 bg-background',
                            )}
                        />
                    ))}
                </span>
            )}
            <span>
                {redeemed} of {entitled} {entitled === 1 ? 'guest' : 'guests'}{' '}
                checked in
            </span>
        </span>
    );
}

export default function MealCheckIn({
    date,
    periods,
    periodId,
    list,
}: {
    date: string | null;
    periods: PeriodOption[];
    periodId: number | null;
    list: List | null;
}) {
    const [search, setSearch] = useState('');
    const [tab, setTab] = useState('all');
    const [busyUnitId, setBusyUnitId] = useState<number | null>(null);
    const searchInputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const handleSlashShortcut = (event: KeyboardEvent) => {
            if (
                event.key !== '/' ||
                event.metaKey ||
                event.ctrlKey ||
                event.altKey
            ) {
                return;
            }

            const target = event.target as HTMLElement | null;

            if (
                target &&
                (target.tagName === 'INPUT' ||
                    target.tagName === 'TEXTAREA' ||
                    target.tagName === 'SELECT' ||
                    target.isContentEditable)
            ) {
                return;
            }

            if (searchInputRef.current) {
                event.preventDefault();
                searchInputRef.current.focus();
            }
        };

        window.addEventListener('keydown', handleSlashShortcut);

        return () => window.removeEventListener('keydown', handleSlashShortcut);
    }, []);

    const allRows = list?.rows ?? [];

    const tabCounts = useMemo(() => {
        const counts = {
            all: allRows.length,
            pending: 0,
            partial: 0,
            redeemed: 0,
        };

        for (const row of allRows) {
            if (row.status === 'pending') {
                counts.pending += 1;
            } else if (row.status === 'partial') {
                counts.partial += 1;
            } else if (row.status === 'redeemed') {
                counts.redeemed += 1;
            }
        }

        return counts;
    }, [allRows]);

    const rows = useMemo(() => {
        const needle = search.trim().toLocaleLowerCase();

        return allRows.filter(
            (row) =>
                (tab === 'all' || row.status === tab) &&
                (needle === '' ||
                    `${row.roomNumber} ${row.guestName} ${row.reservationReference}`
                        .toLocaleLowerCase()
                        .includes(needle)),
        );
    }, [allRows, search, tab]);

    const hasActiveFilter = search.trim() !== '' || tab !== 'all';

    const progressPercent =
        list && list.totalEntitled > 0
            ? Math.min(
                  100,
                  Math.round((list.checkedIn / list.totalEntitled) * 100),
              )
            : 0;

    function switchPeriod(nextPeriodId: string) {
        if (nextPeriodId === String(periodId ?? '')) {
            return;
        }

        router.get(
            index.url(undefined, { query: { period: nextPeriodId } }),
            {},
            { preserveScroll: true },
        );
    }

    function checkIn(row: Row, guests: number) {
        setBusyUnitId(row.reservationUnitId);
        router.post(
            store.url(),
            {
                reservation_unit_id: row.reservationUnitId,
                meal_period_id: periodId,
                service_date: date,
                guests,
            },
            {
                preserveScroll: true,
                onFinish: () => setBusyUnitId(null),
            },
        );
    }

    function undo(row: Row) {
        const last = row.redemptionIds[0];

        if (last === undefined) {
            return;
        }

        setBusyUnitId(row.reservationUnitId);
        router.delete(destroy.url({ redemption: last }), {
            preserveScroll: true,
            onFinish: () => setBusyUnitId(null),
        });
    }

    const activePeriod =
        periods.find((period) => period.value === String(periodId ?? '')) ??
        null;
    const otherPeriods = periods.filter(
        (period) => period.value !== String(periodId ?? ''),
    );

    const filterTabs = [
        { value: 'all', label: 'All', count: tabCounts.all },
        { value: 'pending', label: 'Pending', count: tabCounts.pending },
        { value: 'partial', label: 'Partial', count: tabCounts.partial },
        { value: 'redeemed', label: 'Checked in', count: tabCounts.redeemed },
    ];

    const headerActions =
        periods.length === 0 ? undefined : periods.length <= 4 ? (
            <div
                role="group"
                aria-label="Meal period"
                className="inline-flex flex-wrap items-center gap-1 rounded-xl border bg-muted/60 p-1"
            >
                {periods.map((period) => {
                    const selected = period.value === String(periodId ?? '');
                    const PeriodIcon = periodIcon(period.label);

                    return (
                        <button
                            key={period.value}
                            type="button"
                            aria-pressed={selected}
                            onClick={() => switchPeriod(period.value)}
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition-colors focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none motion-reduce:transition-none max-sm:min-h-10',
                                selected
                                    ? 'bg-card text-foreground shadow-xs ring-1 ring-border'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            <PeriodIcon
                                aria-hidden="true"
                                className="size-3.5 shrink-0"
                            />
                            <span>{period.label}</span>
                        </button>
                    );
                })}
            </div>
        ) : (
            <EnumSelect
                name="meal_period"
                options={periods}
                value={periodId === null ? '' : String(periodId)}
                onValueChange={switchPeriod}
                icon={<Clock aria-hidden />}
                aria-label="Meal period"
                className="w-48"
            />
        );

    return (
        <>
            <Head title="Meal check-in · POS" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Meal check-in"
                    description={
                        date ? (
                            <>
                                Host check-in list for included meals on{' '}
                                <DateText date={date} />.
                            </>
                        ) : (
                            'This property has no open business date.'
                        )
                    }
                    actions={headerActions}
                />

                {list === null ? (
                    <div className="rounded-xl border bg-card p-6">
                        <EmptyState
                            icon={Utensils}
                            title="No meal period to show"
                            description="Add an active meal period under Settings and ensure the property has an open business date."
                        />
                    </div>
                ) : (
                    <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                        <section
                            aria-label="Mobile attendance summary"
                            className="flex flex-col gap-2.5 rounded-xl border bg-card p-4 lg:hidden"
                        >
                            <div className="flex items-baseline justify-between gap-3">
                                <span className="font-heading text-sm font-medium text-foreground">
                                    {activePeriod?.label ?? 'Service'}{' '}
                                    attendance
                                </span>
                                <span className="text-xs text-muted-foreground tabular-nums">
                                    {list.checkedIn} / {list.totalEntitled}{' '}
                                    guests ({progressPercent}%)
                                </span>
                            </div>
                            <Progress
                                value={progressPercent}
                                aria-label="Meal check-in progress"
                            />
                        </section>

                        <section
                            aria-label="Entitled rooms"
                            className="@container min-w-0 overflow-hidden rounded-xl border bg-card"
                        >
                            <header className="flex flex-col gap-3 border-b bg-muted/40 px-4 py-3 @xl:flex-row @xl:items-center @xl:justify-between">
                                <InputGroup className="w-full @xl:max-w-xs">
                                    <InputGroupAddon>
                                        <Search aria-hidden />
                                    </InputGroupAddon>
                                    <InputGroupInput
                                        ref={searchInputRef}
                                        type="search"
                                        aria-label="Search rooms or guests"
                                        placeholder="Room, guest, or ref"
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                    />
                                    {search !== '' ? (
                                        <InputGroupAddon align="inline-end">
                                            <InputGroupButton
                                                size="icon-xs"
                                                aria-label="Clear search"
                                                onClick={() => setSearch('')}
                                            >
                                                <X />
                                            </InputGroupButton>
                                        </InputGroupAddon>
                                    ) : (
                                        <InputGroupAddon
                                            align="inline-end"
                                            className="hidden sm:flex"
                                        >
                                            <Kbd>/</Kbd>
                                        </InputGroupAddon>
                                    )}
                                </InputGroup>

                                <Tabs
                                    value={tab}
                                    onValueChange={setTab}
                                    className="max-w-full overflow-x-auto"
                                >
                                    <TabsList>
                                        {filterTabs.map((item) => (
                                            <TabsTrigger
                                                key={item.value}
                                                value={item.value}
                                                className="gap-1.5 tabular-nums"
                                            >
                                                <span>{item.label}</span>
                                                <span className="text-xs text-muted-foreground">
                                                    {item.count}
                                                </span>
                                            </TabsTrigger>
                                        ))}
                                    </TabsList>
                                </Tabs>
                            </header>

                            {allRows.length === 0 ? (
                                <div className="p-8">
                                    <EmptyState
                                        icon={Utensils}
                                        title={`No rooms entitled for ${activePeriod?.label ?? 'this meal'}`}
                                        description="No in-house booking includes this meal period on the current business date."
                                        action={
                                            otherPeriods.length > 0 ? (
                                                <div className="flex flex-wrap justify-center gap-2">
                                                    {otherPeriods.map(
                                                        (period) => {
                                                            const PeriodIcon =
                                                                periodIcon(
                                                                    period.label,
                                                                );

                                                            return (
                                                                <Button
                                                                    key={
                                                                        period.value
                                                                    }
                                                                    type="button"
                                                                    variant="outline"
                                                                    size="sm"
                                                                    className="max-sm:min-h-11"
                                                                    onClick={() =>
                                                                        switchPeriod(
                                                                            period.value,
                                                                        )
                                                                    }
                                                                >
                                                                    <PeriodIcon data-icon="inline-start" />
                                                                    Check{' '}
                                                                    {
                                                                        period.label
                                                                    }
                                                                </Button>
                                                            );
                                                        },
                                                    )}
                                                </div>
                                            ) : undefined
                                        }
                                    />
                                </div>
                            ) : rows.length === 0 ? (
                                <div className="p-8">
                                    <EmptyState
                                        icon={Search}
                                        title="No matching rooms"
                                        description="No room matches the current search or status tab."
                                        action={
                                            hasActiveFilter ? (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    className="max-sm:min-h-11"
                                                    onClick={() => {
                                                        setSearch('');
                                                        setTab('all');
                                                    }}
                                                >
                                                    Clear filters
                                                </Button>
                                            ) : undefined
                                        }
                                    />
                                </div>
                            ) : (
                                <div className="divide-y">
                                    {rows.map((row) => {
                                        const busy =
                                            busyUnitId ===
                                            row.reservationUnitId;
                                        const isDone =
                                            row.remainingGuests === 0;
                                        const status = STATUS_BADGE[
                                            row.status
                                        ] ?? {
                                            value: row.status,
                                            label: row.status,
                                            tone: 'neutral' as const,
                                        };

                                        return (
                                            <div
                                                key={row.reservationUnitId}
                                                className="flex flex-col gap-3 px-4 py-3.5 transition-colors hover:bg-muted/30 motion-reduce:transition-none @xl:flex-row @xl:items-center @xl:justify-between"
                                            >
                                                <div className="flex min-w-0 items-center gap-3.5">
                                                    <div
                                                        className={cn(
                                                            'flex size-12 shrink-0 flex-col items-center justify-center rounded-xl border font-heading text-sm font-bold tabular-nums',
                                                            isDone
                                                                ? 'border-success/30 bg-success/10 text-success'
                                                                : 'border-border bg-muted/60 text-foreground',
                                                        )}
                                                    >
                                                        <span className="truncate px-1">
                                                            {row.roomNumber ||
                                                                'Unassigned'}
                                                        </span>
                                                    </div>

                                                    <div className="flex min-w-0 flex-col gap-1">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="truncate font-medium text-foreground">
                                                                {row.guestName}
                                                            </span>
                                                            <StatusBadge
                                                                status={status}
                                                            />
                                                        </div>
                                                        <div className="flex flex-col gap-1 @xl:flex-row @xl:flex-wrap @xl:items-center @xl:gap-x-2.5">
                                                            <span className="font-mono text-xs text-muted-foreground">
                                                                {
                                                                    row.reservationReference
                                                                }
                                                            </span>
                                                            <span
                                                                aria-hidden="true"
                                                                className="hidden text-muted-foreground/50 @xl:inline"
                                                            >
                                                                ·
                                                            </span>
                                                            <GuestSeatsIndicator
                                                                entitled={
                                                                    row.entitledGuests
                                                                }
                                                                redeemed={
                                                                    row.redeemedGuests
                                                                }
                                                            />
                                                        </div>
                                                    </div>
                                                </div>

                                                <div className="flex flex-wrap items-center justify-end gap-2">
                                                    {row.redemptionIds.length >
                                                        0 && (
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger
                                                                render={
                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="icon-sm"
                                                                        className="max-sm:size-11"
                                                                        aria-label={`More actions for room ${row.roomNumber}`}
                                                                        disabled={
                                                                            busy
                                                                        }
                                                                    />
                                                                }
                                                            >
                                                                <MoreHorizontal />
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end">
                                                                <DropdownMenuItem
                                                                    onClick={() =>
                                                                        undo(
                                                                            row,
                                                                        )
                                                                    }
                                                                >
                                                                    <Undo2
                                                                        aria-hidden
                                                                        className="size-4"
                                                                    />
                                                                    Undo last
                                                                    check-in
                                                                </DropdownMenuItem>
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    )}

                                                    {row.remainingGuests >=
                                                        2 && (
                                                        <PartialPopover
                                                            row={row}
                                                            disabled={busy}
                                                            onCheckIn={checkIn}
                                                            className="max-sm:min-h-11"
                                                        />
                                                    )}

                                                    {row.remainingGuests >
                                                        0 && (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            className="max-sm:min-h-11"
                                                            aria-label={`Check in ${row.remainingGuests === row.entitledGuests ? 'all' : row.remainingGuests} for room ${row.roomNumber}`}
                                                            disabled={busy}
                                                            focusableWhenDisabled
                                                            onClick={() =>
                                                                checkIn(
                                                                    row,
                                                                    row.remainingGuests,
                                                                )
                                                            }
                                                        >
                                                            {busy ? (
                                                                <Spinner data-icon="inline-start" />
                                                            ) : (
                                                                <Check data-icon="inline-start" />
                                                            )}
                                                            {row.remainingGuests ===
                                                            row.entitledGuests
                                                                ? `Check in all (${row.remainingGuests})`
                                                                : `Check in ${row.remainingGuests}`}
                                                        </Button>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </section>

                        <aside
                            aria-label="Service attendance summary"
                            className="hidden flex-col gap-4 lg:sticky lg:top-4 lg:flex"
                        >
                            <div className="flex flex-col gap-5 rounded-xl border bg-card p-5">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="flex flex-col gap-0.5">
                                        <h2 className="font-heading text-base font-medium text-foreground">
                                            {activePeriod?.label ?? 'Service'}{' '}
                                            tally
                                        </h2>
                                        {date && (
                                            <p className="text-xs text-muted-foreground">
                                                <DateText date={date} />
                                            </p>
                                        )}
                                    </div>
                                    <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-muted-foreground tabular-nums">
                                        <Users
                                            className="size-3"
                                            aria-hidden="true"
                                        />
                                        {allRows.length}{' '}
                                        {allRows.length === 1
                                            ? 'room'
                                            : 'rooms'}
                                    </span>
                                </div>

                                <AttendanceRing
                                    checkedIn={list.checkedIn}
                                    totalEntitled={list.totalEntitled}
                                    percent={progressPercent}
                                />

                                <dl className="divide-y rounded-lg border bg-muted/20 text-sm tabular-nums">
                                    <div className="flex items-center justify-between px-3 py-2.5">
                                        <dt className="text-muted-foreground">
                                            Remaining
                                        </dt>
                                        <dd className="font-heading text-base font-semibold text-foreground">
                                            {list.remaining}
                                        </dd>
                                    </div>
                                    <div className="flex items-center justify-between px-3 py-2.5">
                                        <dt className="text-muted-foreground">
                                            Checked in
                                        </dt>
                                        <dd className="font-heading text-base font-semibold text-foreground">
                                            {list.checkedIn}
                                        </dd>
                                    </div>
                                    <div className="flex items-center justify-between px-3 py-2.5">
                                        <dt className="text-muted-foreground">
                                            Entitled
                                        </dt>
                                        <dd className="font-heading text-base font-semibold text-foreground">
                                            {list.totalEntitled}
                                        </dd>
                                    </div>
                                </dl>
                            </div>

                            <div className="flex flex-col gap-3 rounded-xl border bg-card p-4 text-xs">
                                <h3 className="font-medium text-foreground">
                                    Room breakdown
                                </h3>
                                <dl className="flex flex-col gap-2 tabular-nums">
                                    <div className="flex items-center justify-between">
                                        <dt className="text-muted-foreground">
                                            Waiting to arrive
                                        </dt>
                                        <dd className="font-medium text-foreground">
                                            {tabCounts.pending}
                                        </dd>
                                    </div>
                                    <div className="flex items-center justify-between">
                                        <dt className="text-muted-foreground">
                                            Partially arrived
                                        </dt>
                                        <dd className="font-medium text-foreground">
                                            {tabCounts.partial}
                                        </dd>
                                    </div>
                                    <div className="flex items-center justify-between">
                                        <dt className="text-muted-foreground">
                                            Completed
                                        </dt>
                                        <dd className="font-medium text-foreground">
                                            {tabCounts.redeemed}
                                        </dd>
                                    </div>
                                </dl>
                                <p className="border-t pt-2.5 text-muted-foreground">
                                    Tip: press <Kbd>/</Kbd> to jump straight to
                                    room search.
                                </p>
                            </div>
                        </aside>
                    </div>
                )}
            </div>
        </>
    );
}

MealCheckIn.layout = {
    breadcrumbs: [{ title: 'Meal check-in', href: index() }],
};
