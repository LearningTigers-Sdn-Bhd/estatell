import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Ellipsis,
    Plus,
    Ship,
    TriangleAlert,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { BoatSailingDialog } from '@/components/pms/boats/boat-sailing-dialog';
import { BoatTimeDialog } from '@/components/pms/boats/boat-time-dialog';
import { BoatTripDialog } from '@/components/pms/boats/boat-trip-dialog';
import { BulkActionBar } from '@/components/pms/bulk-action-bar';
import { DatePicker } from '@/components/pms/date-picker';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { ReportExportMenu } from '@/components/pms/reports/report-export-menu';
import { ReportLayoutSheet } from '@/components/pms/reports/report-layout-sheet';
import { ChangeBoatTripDialog } from '@/components/pms/reservations/change-boat-trip-dialog';
import { StatusBadge } from '@/components/pms/status-badge';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { index, pdf, spreadsheet } from '@/routes/boats';
import { cancel, reset } from '@/routes/boats/departures';
import { destroy } from '@/routes/boats/sailings';
import { move, setTick as setTickRoute, tick } from '@/routes/boats/trips';
import { show } from '@/routes/reservations';

type Group = App.Data.Boats.BoatBoardGroupData;
type Row = App.Data.Reports.BoatManifestRow;

type Props = {
    date: string;
    today: string;
    isPast: boolean;
    direction: 'in' | 'out' | 'both';
    groups: Group[];
    hasDepartures: boolean;
    boats: App.Data.Boats.BoatData[];
    crew: App.Data.Boats.BoatCrewMemberData[];
    boatDepartures?: App.Data.Boats.BoatDepartureOptionData[];
    layout: App.Data.Reports.ReportLayoutData;
    orientationOptions: EnumOption[];
    can: { manage: boolean; columns: boolean };
    settingsUrl: string | null;
};

const DIRECTIONS = [
    { value: 'in', label: 'Boat in' },
    { value: 'out', label: 'Boat out' },
    { value: 'both', label: 'Both' },
];

const MANIFEST_SORT = 'departure';

function shiftDay(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);
    const next = new Date(year, month - 1, day + days);

    return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}-${String(next.getDate()).padStart(2, '0')}`;
}

function dayLabel(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}

function moveTarget(group: Group): string {
    return group.sailingId === null
        ? `d:${group.departureId}`
        : `s:${group.sailingId}`;
}

function tickLabel(direction: string | null): string {
    return direction === 'in'
        ? 'Arrived'
        : direction === 'out'
          ? 'Left'
          : 'Arrived or left';
}

function paxText(row: Row): string {
    return `${row.adults}A ${row.children}C`;
}

export default function BoatsIndex({
    date,
    today,
    isPast,
    direction,
    groups,
    hasDepartures,
    boats,
    crew,
    boatDepartures,
    layout,
    orientationOptions,
    can,
    settingsUrl,
}: Props) {
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [dialog, setDialog] = useState<{
        mode: 'change' | 'add' | 'time';
        group: Group;
    } | null>(null);
    const [tripOpen, setTripOpen] = useState(false);
    const [cancelGroup, setCancelGroup] = useState<Group | null>(null);
    const [cancelling, setCancelling] = useState(false);
    const [setBoat, setSetBoat] = useState<{
        row: Row;
        open: boolean;
    } | null>(null);
    const [busy, setBusy] = useState(false);
    const [layoutDraft, setLayoutDraft] =
        useState<App.Data.Reports.ReportLayoutData | null>(null);
    const lastView = useRef(`${date}|${direction}`);

    // A new date or direction is a new list: drop the old ticks.
    if (lastView.current !== `${date}|${direction}`) {
        lastView.current = `${date}|${direction}`;

        if (selected.size > 0) {
            setSelected(new Set());
        }
    }

    const visit = (next: { date?: string; direction?: string }) =>
        router.get(
            index.url(undefined, {
                query: {
                    date: next.date ?? date,
                    direction: next.direction ?? direction,
                },
            }),
            {},
            { preserveScroll: true, preserveState: true },
        );

    const rowsByKey = useMemo(() => {
        const map = new Map<number, { row: Row; group: Group }>();

        for (const group of groups) {
            for (const row of group.rows) {
                if (!row.not_set) {
                    map.set(row.key, { row, group });
                }
            }
        }

        return map;
    }, [groups]);

    const picked = [...selected].filter((key) => rowsByKey.has(key));
    const pickedRows = picked.map((key) => rowsByKey.get(key)!);
    const pickedDirections = new Set(
        pickedRows.map((item) => item.row.direction),
    );
    const sameDirection =
        pickedDirections.size === 1 ? [...pickedDirections][0] : null;
    const pickedGroups = new Set(pickedRows.map((item) => item.group.key));

    const moveOptions = groups
        .filter(
            (group) =>
                group.kind === 'sailing' &&
                !group.cancelled &&
                group.direction === sameDirection &&
                !(pickedGroups.size === 1 && pickedGroups.has(group.key)),
        )
        .map((group) => ({
            value: moveTarget(group),
            label: `${group.time} · ${group.boat ?? 'No boat'} · ${seatsText(group)}`,
        }));

    const clear = () => setSelected(new Set());

    const after = (page: { props: Record<string, unknown> }) => {
        const flash = (page.props.flash ?? {}) as {
            toast?: { type?: string };
        };

        if (flash.toast?.type !== 'error') {
            clear();
        }
    };

    const send = (url: string, data: Record<string, unknown>) =>
        router.post(url, data as never, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: after,
        });

    const toggle = (keys: number[], on: boolean) =>
        setSelected((current) => {
            const next = new Set(current);

            for (const key of keys) {
                if (on) {
                    next.add(key);
                } else {
                    next.delete(key);
                }
            }

            return next;
        });

    const setTick = (row: Row, ticked: boolean) =>
        router.put(
            setTickRoute.url({ trip: row.key }),
            { ticked },
            { preserveScroll: true, preserveState: true },
        );

    const moveTo = (value: string) => {
        if (value === '') {
            return;
        }

        const [kind, id] = value.split(':');

        send(move.url(), {
            trip_ids: picked,
            ...(kind === 's'
                ? { boat_sailing_id: Number(id) }
                : { boat_departure_id: Number(id), date }),
        });
    };

    const allLeaving = pickedRows.every((item) => item.row.direction === 'out');
    const exportDates = useMemo(() => ({ date, direction }), [date, direction]);
    const manage = can.manage && !isPast;

    return (
        <>
            <Head title="Boat Trips" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Boat Trips"
                    description="The boats of one date: who goes on which boat, the crew in charge, and who has arrived or left."
                    actions={
                        hasDepartures ? (
                            <>
                                {manage && (
                                    <Button
                                        type="button"
                                        onClick={() => setTripOpen(true)}
                                    >
                                        <Plus data-icon="inline-start" />
                                        Trip or job
                                    </Button>
                                )}
                                <ReportExportMenu
                                    report="boat-manifest"
                                    label="Export Boat Manifest"
                                    dates={exportDates}
                                    sort={MANIFEST_SORT}
                                    rowKeys={[]}
                                    pdfUrl={pdf.url()}
                                    spreadsheetUrl={spreadsheet.url()}
                                    onColumns={
                                        can.columns
                                            ? () => setLayoutDraft(layout)
                                            : undefined
                                    }
                                />
                            </>
                        ) : undefined
                    }
                />

                {!hasDepartures ? (
                    <EmptyState
                        icon={Ship}
                        title="No departures yet"
                        description="Add departures in Settings, Boats."
                        action={
                            settingsUrl ? (
                                <Link
                                    href={settingsUrl}
                                    className={buttonVariants({
                                        variant: 'outline',
                                    })}
                                >
                                    Open Boats settings
                                </Link>
                            ) : undefined
                        }
                    />
                ) : (
                    <>
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="flex items-center gap-1">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    aria-label="Previous day"
                                    onClick={() =>
                                        visit({ date: shiftDay(date, -1) })
                                    }
                                >
                                    <ChevronLeft />
                                </Button>
                                <DatePicker
                                    value={date}
                                    onChange={(next) =>
                                        next && visit({ date: next })
                                    }
                                    className="w-auto"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    aria-label="Next day"
                                    onClick={() =>
                                        visit({ date: shiftDay(date, 1) })
                                    }
                                >
                                    <ChevronRight />
                                </Button>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => visit({ date: today })}
                            >
                                Today
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    visit({ date: shiftDay(today, 1) })
                                }
                            >
                                Tomorrow
                            </Button>
                            <ToggleGroup
                                variant="outline"
                                spacing={0}
                                value={[direction]}
                                onValueChange={(next) =>
                                    next[0] && visit({ direction: next[0] })
                                }
                                aria-label="Direction"
                                className="ml-auto"
                            >
                                {DIRECTIONS.map((option) => (
                                    <ToggleGroupItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                        </div>

                        {groups.length === 0 ? (
                            <EmptyState
                                icon={Ship}
                                title={`No boats on ${dayLabel(date)}.`}
                                description="Pick another date or direction to see the boats."
                                action={
                                    manage ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setTripOpen(true)}
                                        >
                                            <Plus data-icon="inline-start" />
                                            Trip or job
                                        </Button>
                                    ) : undefined
                                }
                            />
                        ) : (
                            <div className="flex flex-col gap-6">
                                {groups.map((group) => (
                                    <GroupSection
                                        key={group.key}
                                        group={group}
                                        selected={selected}
                                        onSelect={toggle}
                                        canSelect={can.manage}
                                        canTick={can.manage}
                                        menu={manage}
                                        date={date}
                                        onTick={setTick}
                                        onChange={() =>
                                            setDialog({ mode: 'change', group })
                                        }
                                        onAdd={() =>
                                            setDialog({ mode: 'add', group })
                                        }
                                        onTime={() =>
                                            setDialog({ mode: 'time', group })
                                        }
                                        onCancel={() => setCancelGroup(group)}
                                        onUndo={() =>
                                            router.put(
                                                cancel.url({
                                                    departure:
                                                        group.departureId!,
                                                }),
                                                { date, cancelled: false },
                                                { preserveScroll: true },
                                            )
                                        }
                                        onSetBoat={(row) =>
                                            setSetBoat({ row, open: true })
                                        }
                                        canSetBoat={manage}
                                    />
                                ))}
                            </div>
                        )}
                    </>
                )}

                <BulkActionBar
                    count={picked.length}
                    onClear={clear}
                    disabled={busy}
                    stackOnMobile
                >
                    {sameDirection !== null && moveOptions.length > 0 && (
                        <EnumSelect
                            options={moveOptions}
                            value=""
                            placeholder="Move to"
                            aria-label="Move to another boat"
                            onValueChange={moveTo}
                            className="w-56"
                            menuClassName="w-72"
                        />
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        disabled={busy}
                        focusableWhenDisabled
                        onClick={() =>
                            send(tick.url(), { trip_ids: picked, ticked: true })
                        }
                    >
                        {allLeaving ? 'Mark left' : 'Mark arrived'}
                    </Button>
                    <ReportExportMenu
                        report="boat-manifest"
                        dates={exportDates}
                        sort={MANIFEST_SORT}
                        rowKeys={picked.map(String)}
                        pdfUrl={pdf.url()}
                        spreadsheetUrl={spreadsheet.url()}
                        side="top"
                    />
                </BulkActionBar>
            </div>

            {dialog && dialog.mode === 'time' && (
                <BoatTimeDialog
                    open
                    onOpenChange={(open) => !open && setDialog(null)}
                    group={dialog.group}
                    date={date}
                    dateLabel={dayLabel(date)}
                />
            )}

            {dialog && dialog.mode !== 'time' && (
                <BoatSailingDialog
                    open
                    onOpenChange={(open) => !open && setDialog(null)}
                    mode={dialog.mode}
                    group={dialog.group}
                    date={date}
                    dateLabel={dayLabel(date)}
                    boats={boats}
                    crew={crew}
                />
            )}

            {tripOpen && (
                <BoatTripDialog
                    open
                    onOpenChange={setTripOpen}
                    date={date}
                    dateLabel={dayLabel(date)}
                    boats={boats}
                    crew={crew}
                />
            )}

            <AlertDialog
                open={cancelGroup !== null}
                onOpenChange={(open) => !open && setCancelGroup(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Cancel the {cancelGroup?.time}{' '}
                            {cancelGroup?.directionLabel?.toLowerCase()}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            It will not run on {dayLabel(date)} and leaves the
                            booking pickers for that date. You can undo this on
                            the board.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep trip</AlertDialogCancel>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={cancelling}
                            focusableWhenDisabled
                            onClick={() =>
                                cancelGroup?.departureId != null &&
                                router.put(
                                    cancel.url({
                                        departure: cancelGroup.departureId,
                                    }),
                                    { date, cancelled: true },
                                    {
                                        preserveScroll: true,
                                        onStart: () => setCancelling(true),
                                        onFinish: () => {
                                            setCancelling(false);
                                            setCancelGroup(null);
                                        },
                                    },
                                )
                            }
                        >
                            {cancelling && <Spinner data-icon="inline-start" />}
                            Cancel trip
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {can.columns && layoutDraft && (
                <ReportLayoutSheet
                    open
                    onOpenChange={(next) => {
                        if (!next) {
                            setLayoutDraft(null);
                        }
                    }}
                    report="boat-manifest"
                    reportLabel="Boat manifest"
                    saved={layout}
                    draft={layoutDraft}
                    onDraftChange={setLayoutDraft}
                    orientations={orientationOptions}
                    dates={exportDates}
                    onDone={() => setLayoutDraft(null)}
                    description="Choose the columns, in the order they show, and how each aligns. Columns change Excel and CSV only. The PDF is a fixed sheet."
                />
            )}

            {setBoat && (
                <ChangeBoatTripDialog
                    open={setBoat.open}
                    onOpenChange={(open) =>
                        setSetBoat((current) =>
                            open || current === null
                                ? current
                                : { ...current, open },
                        )
                    }
                    reservationId={setBoat.row.reservation_id ?? 0}
                    unit={{
                        id: setBoat.row.reservation_unit_id ?? 0,
                        roomType: setBoat.row.unit,
                        primaryGuest: setBoat.row.lead_guest,
                        boatIn: tripOf(setBoat.row, 'in'),
                        boatOut: tripOf(setBoat.row, 'out'),
                        arrival: date,
                        departure: date,
                        arrivalTime: setBoat.row.unit_time ?? '',
                        departureTime: setBoat.row.unit_time ?? '',
                    }}
                    direction={setBoat.row.direction as 'in' | 'out'}
                    departures={boatDepartures}
                />
            )}
        </>
    );
}

BoatsIndex.layout = {
    breadcrumbs: [{ title: 'Boat Trips', href: index() }],
};

/**
 * The trip a row already has, so the Change boat Dialog opens on it. A Boat not set row has none.
 */
function tripOf(
    row: Row,
    direction: 'in' | 'out',
): App.Data.Boats.UnitBoatTripData | null {
    if (row.not_set || row.direction !== direction || row.mode === null) {
        return null;
    }

    return {
        direction,
        mode: row.mode as App.Data.Boats.UnitBoatTripData['mode'],
        modeLabel: row.mode,
        boatDepartureId: row.boat_departure_id,
        departureAvailable: true,
        time: row.mode === 'scheduled' ? null : row.unit_time,
        note: row.note,
        summary: row.departure,
    };
}

function seatsText(group: Group): string {
    return group.seats === null
        ? `${group.taken}`
        : `${group.taken} / ${group.seats}`;
}

function GroupMenu({
    group,
    date,
    onChange,
    onAdd,
    onTime,
    onCancel,
}: {
    group: Group;
    date: string;
    onChange: () => void;
    onAdd: () => void;
    onTime: () => void;
    onCancel: () => void;
}) {
    if (group.isExtra && !group.canRemove) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                render={
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={`Actions for ${group.title}`}
                    />
                }
            >
                <Ellipsis />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-48">
                {!group.isExtra && (
                    <>
                        <DropdownMenuItem onClick={onChange}>
                            Change boat and crew
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={onTime}>
                            Change time
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={onAdd}>
                            Add boat
                        </DropdownMenuItem>
                    </>
                )}
                {!group.isExtra && <DropdownMenuSeparator />}
                {group.canRemove && group.sailingId !== null && (
                    <DropdownMenuItem
                        onClick={() =>
                            router.delete(
                                destroy.url({ sailing: group.sailingId! }),
                                { preserveScroll: true },
                            )
                        }
                    >
                        {group.isExtra ? 'Delete' : 'Remove boat'}
                    </DropdownMenuItem>
                )}
                {group.hasStored && group.departureId !== null && (
                    <DropdownMenuItem
                        onClick={() =>
                            router.put(
                                reset.url({ departure: group.departureId! }),
                                { date },
                                { preserveScroll: true },
                            )
                        }
                    >
                        Default
                    </DropdownMenuItem>
                )}
                {!group.isExtra && (
                    <DropdownMenuItem
                        variant="destructive"
                        disabled={group.departureGuests > 0}
                        onClick={onCancel}
                    >
                        <span className="flex flex-col">
                            Cancel trip
                            {group.departureGuests > 0 && (
                                <span className="text-xs font-normal text-muted-foreground">
                                    Move the {group.departureGuests}{' '}
                                    {group.departureGuests === 1
                                        ? 'guest'
                                        : 'guests'}{' '}
                                    first.
                                </span>
                            )}
                        </span>
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function GroupSection({
    group,
    selected,
    onSelect,
    canSelect,
    canTick,
    menu,
    date,
    onTick,
    onChange,
    onAdd,
    onTime,
    onCancel,
    onUndo,
    onSetBoat,
    canSetBoat,
}: {
    group: Group;
    selected: Set<number>;
    onSelect: (keys: number[], on: boolean) => void;
    canSelect: boolean;
    canTick: boolean;
    menu: boolean;
    date: string;
    onTick: (row: Row, ticked: boolean) => void;
    onChange: () => void;
    onAdd: () => void;
    onTime: () => void;
    onCancel: () => void;
    onUndo: () => void;
    onSetBoat: (row: Row) => void;
    canSetBoat: boolean;
}) {
    const isSailing = group.kind === 'sailing';
    const isNotSet = group.kind === 'not_set';
    const isMixed = group.kind === 'charter' || group.kind === 'own';
    const hasMenu = !isNotSet;
    const pax = group.rows.reduce((sum, row) => sum + row.total, 0);
    const selectable = group.rows
        .filter((row) => !row.not_set)
        .map((row) => row.key);
    const pickedCount = selectable.filter((key) => selected.has(key)).length;
    const over = group.seats !== null && group.taken > group.seats;
    const crewText = [
        group.inCharge ? `⚓ ${group.inCharge}` : null,
        ...group.assistants,
    ]
        .filter(Boolean)
        .join(' · ');

    if (group.cancelled) {
        return (
            <section className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border bg-muted/30 px-3 py-2 text-muted-foreground">
                <h2 className="text-sm font-medium">
                    {group.directionLabel} · {group.times} · Cancelled
                </h2>
                {menu && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="ml-auto"
                        onClick={onUndo}
                    >
                        Undo
                    </Button>
                )}
            </section>
        );
    }

    return (
        <section className="flex flex-col overflow-hidden rounded-lg border">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 bg-muted/50 px-3 py-2">
                {canSelect && selectable.length > 0 && (
                    <Checkbox
                        aria-label={`Select all rows in ${group.title}`}
                        checked={pickedCount === selectable.length}
                        indeterminate={
                            pickedCount > 0 && pickedCount < selectable.length
                        }
                        onCheckedChange={(on) => onSelect(selectable, on)}
                    />
                )}
                {isNotSet && (
                    <TriangleAlert
                        aria-hidden
                        className="size-4 text-warning"
                    />
                )}
                <h2 className="text-sm font-medium">
                    {isSailing
                        ? `${group.directionLabel} · ${group.times}`
                        : group.title}
                </h2>
                {isMixed && (
                    <span className="ml-auto text-sm text-muted-foreground">
                        {group.rows.length === 1
                            ? '1 room'
                            : `${group.rows.length} rooms`}{' '}
                        · {pax} pax
                    </span>
                )}
                {isSailing && (
                    <span className="text-sm text-muted-foreground">
                        {group.isExtra ? 'Extra trip · ' : ''}
                        {[group.boat, crewText].filter(Boolean).join(' · ') ||
                            'No boat set'}
                    </span>
                )}
                {isSailing && (
                    <span className="ml-auto flex items-center gap-1">
                        <StatusBadge
                            status={{
                                value: over ? 'over' : 'seats',
                                label: `${seatsText(group)} seats`,
                                tone: over ? 'warning' : 'neutral',
                            }}
                            dot={false}
                        />
                        {menu && (
                            <GroupMenu
                                group={group}
                                date={date}
                                onChange={onChange}
                                onAdd={onAdd}
                                onTime={onTime}
                                onCancel={onCancel}
                            />
                        )}
                    </span>
                )}
            </div>

            {group.rows.length === 0 ? (
                <p className="px-3 py-3 text-sm text-muted-foreground">
                    No guests.
                </p>
            ) : (
                <>
                    <div className="hidden md:block">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    {canSelect && <TableHead className="w-8" />}
                                    <TableHead>Room</TableHead>
                                    <TableHead>Lead guest</TableHead>
                                    <TableHead>Agent</TableHead>
                                    <TableHead>Phone</TableHead>
                                    {isMixed && <TableHead>Trip</TableHead>}
                                    <TableHead>Pax</TableHead>
                                    {!isMixed && <TableHead>Note</TableHead>}
                                    <TableHead className="text-right">
                                        {isNotSet
                                            ? ''
                                            : isMixed
                                              ? 'Done'
                                              : tickLabel(group.direction)}
                                    </TableHead>
                                    {hasMenu && (
                                        <TableHead className="w-10">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {group.rows.map((row) => (
                                    <TableRow key={row.key}>
                                        {canSelect && (
                                            <TableCell>
                                                {!row.not_set && (
                                                    <Checkbox
                                                        aria-label={`Select ${row.lead_guest}`}
                                                        checked={selected.has(
                                                            row.key,
                                                        )}
                                                        onCheckedChange={(on) =>
                                                            onSelect(
                                                                [row.key],
                                                                on,
                                                            )
                                                        }
                                                    />
                                                )}
                                            </TableCell>
                                        )}
                                        <TableCell className="font-medium tabular-nums">
                                            {row.unit}
                                        </TableCell>
                                        <TableCell>{row.lead_guest}</TableCell>
                                        <TableCell>{row.agent}</TableCell>
                                        <TableCell className="tabular-nums">
                                            {row.phone ?? '—'}
                                        </TableCell>
                                        {isMixed && (
                                            <TableCell>
                                                {tripText(row)}
                                            </TableCell>
                                        )}
                                        <TableCell className="tabular-nums">
                                            {paxText(row)}
                                        </TableCell>
                                        {!isMixed && (
                                            <TableCell className="text-muted-foreground">
                                                {row.note}
                                            </TableCell>
                                        )}
                                        <TableCell className="text-right">
                                            <RowEnd
                                                row={row}
                                                canTick={canTick}
                                                canSetBoat={canSetBoat}
                                                onTick={onTick}
                                                onSetBoat={onSetBoat}
                                            />
                                        </TableCell>
                                        {hasMenu && (
                                            <TableCell>
                                                <RowMenu
                                                    row={row}
                                                    canChangeBoat={menu}
                                                    onChangeBoat={onSetBoat}
                                                />
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <ul className="flex flex-col divide-y md:hidden">
                        {group.rows.map((row) => (
                            <li
                                key={row.key}
                                className="flex items-center gap-3 px-3 py-2"
                            >
                                {canSelect && !row.not_set && (
                                    <Checkbox
                                        aria-label={`Select ${row.lead_guest}`}
                                        checked={selected.has(row.key)}
                                        onCheckedChange={(on) =>
                                            onSelect([row.key], on)
                                        }
                                    />
                                )}
                                <div className="flex min-w-0 flex-1 flex-col">
                                    <span className="truncate text-sm font-medium">
                                        {row.unit} · {row.lead_guest} ·{' '}
                                        {paxText(row)}
                                    </span>
                                    <span className="truncate text-xs text-muted-foreground">
                                        {[
                                            isMixed ? tripText(row) : null,
                                            row.agent,
                                            row.phone,
                                            isMixed ? null : row.note,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </span>
                                </div>
                                <RowEnd
                                    row={row}
                                    canTick={canTick}
                                    canSetBoat={canSetBoat}
                                    onTick={onTick}
                                    onSetBoat={onSetBoat}
                                />
                                {hasMenu && (
                                    <RowMenu
                                        row={row}
                                        canChangeBoat={menu}
                                        onChangeBoat={onSetBoat}
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}

/** Charter and Own boat rows mix both directions, so the Trip column says which: "In · 10:30 · Wedding party". */
function tripText(row: Row): string {
    return [row.direction === 'in' ? 'In' : 'Out', row.unit_time, row.note]
        .filter(Boolean)
        .join(' · ');
}

function RowMenu({
    row,
    canChangeBoat,
    onChangeBoat,
}: {
    row: Row;
    canChangeBoat: boolean;
    onChangeBoat: (row: Row) => void;
}) {
    if (row.not_set) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                render={
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={`Actions for ${row.lead_guest}`}
                    />
                }
            >
                <Ellipsis />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-44">
                {canChangeBoat && (
                    <DropdownMenuItem onClick={() => onChangeBoat(row)}>
                        Change boat
                    </DropdownMenuItem>
                )}
                {row.reservation_id !== null && (
                    <DropdownMenuItem
                        render={
                            <Link
                                href={show.url({
                                    reservation: row.reservation_id,
                                })}
                            />
                        }
                    >
                        Open reservation
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function RowEnd({
    row,
    canTick,
    canSetBoat,
    onTick,
    onSetBoat,
}: {
    row: Row;
    canTick: boolean;
    canSetBoat: boolean;
    onTick: (row: Row, ticked: boolean) => void;
    onSetBoat: (row: Row) => void;
}) {
    if (row.not_set) {
        return canSetBoat ? (
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => onSetBoat(row)}
            >
                Set boat
            </Button>
        ) : null;
    }

    return (
        <Checkbox
            aria-label={`${row.lead_guest}: ${row.direction === 'in' ? 'arrived' : 'left'}`}
            checked={row.ticked}
            disabled={!canTick}
            onCheckedChange={(checked) => onTick(row, checked === true)}
        />
    );
}
