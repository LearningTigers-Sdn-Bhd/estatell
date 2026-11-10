import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowUpRight,
    Baby,
    Ban,
    Check,
    ChevronLeft,
    ChevronRight,
    Clock,
    Ellipsis,
    LogIn,
    LogOut,
    Plus,
    RotateCcw,
    Sailboat,
    Settings2,
    Ship,
    Trash2,
    TriangleAlert,
    Undo2,
    User,
    Users,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import {
    BoatJobsSection,
    jobName,
} from '@/components/pms/boats/boat-jobs-section';
import { BoatNoteField } from '@/components/pms/boats/boat-note-field';
import { BoatSailingDialog } from '@/components/pms/boats/boat-sailing-dialog';
import {
    BoatTickDialog,
    tickWord,
} from '@/components/pms/boats/boat-tick-dialog';
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
import { TabLinks } from '@/components/pms/tab-links';
import type { TabLink } from '@/components/pms/tab-links';
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
import { index, pdf, spreadsheet } from '@/routes/boats';
import { cancel, reset } from '@/routes/boats/departures';
import { destroy as destroyJob } from '@/routes/boats/jobs';
import { destroy } from '@/routes/boats/sailings';
import { move, tick } from '@/routes/boats/trips';
import { show } from '@/routes/reservations';

type Group = App.Data.Boats.BoatBoardGroupData;
type Row = App.Data.Reports.BoatManifestRow;
type Job = App.Data.Boats.BoatJobData;

type Props = {
    date: string;
    today: string;
    isPast: boolean;
    direction: 'in' | 'out' | 'both' | 'other';
    groups: Group[];
    hasDepartures: boolean;
    boats: App.Data.Boats.BoatData[];
    crew: App.Data.Boats.BoatCrewMemberData[];
    boatDepartures?: App.Data.Boats.BoatDepartureOptionData[];
    boatPicks?: App.Data.Boats.BoatPickData[];
    sailingCrews: App.Data.Boats.BoatSailingCrewData[];
    jobTypes: EnumOption[];
    layout: App.Data.Reports.ReportLayoutData;
    orientationOptions: EnumOption[];
    can: { manage: boolean; columns: boolean };
    settingsUrl: string | null;
};

const TABS = [
    { value: 'both', label: 'All', icon: Ship },
    { value: 'in', label: 'Boat in', icon: LogIn },
    { value: 'out', label: 'Boat out', icon: LogOut },
    { value: 'other', label: 'Others', icon: Sailboat },
] as const;

/** The manifest filters of a tab: Boat in and Boat out export the scheduled boats only, Others Charter, Own boat and the boat jobs. */
function exportFilters(
    date: string,
    direction: Props['direction'],
): Record<string, string> {
    if (direction === 'both') {
        return { date, direction };
    }

    if (direction === 'other') {
        return { date, direction: 'both', boat_group: 'other' };
    }

    return { date, direction, boat_group: 'scheduled' };
}

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

/** Adults and children as icons with counts, as on Housekeeping. */
function PaxIcons({ row }: { row: Row }) {
    return (
        <span className="flex shrink-0 items-center gap-3 text-sm tabular-nums">
            <span
                className="flex items-center gap-1"
                aria-label={`${row.adults} ${row.adults === 1 ? 'adult' : 'adults'}`}
            >
                <User aria-hidden className="size-4" />
                {row.adults}
            </span>
            <span
                className="flex items-center gap-1"
                aria-label={`${row.children} ${row.children === 1 ? 'child' : 'children'}`}
            >
                <Baby aria-hidden className="size-4" />
                {row.children}
            </span>
        </span>
    );
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
    boatPicks,
    sailingCrews,
    jobTypes,
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
    const [editJob, setEditJob] = useState<Job | null>(null);
    const [deleteJob, setDeleteJob] = useState<Job | null>(null);
    const [deleting, setDeleting] = useState(false);
    const [cancelGroup, setCancelGroup] = useState<Group | null>(null);
    const [cancelling, setCancelling] = useState(false);
    const [setBoat, setSetBoat] = useState<{
        row: Row;
        open: boolean;
    } | null>(null);
    const [busy, setBusy] = useState(false);
    const [tickAsk, setTickAsk] = useState<{
        row: Row;
        ticked: boolean;
    } | null>(null);
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

    const urlFor = (next: { date?: string; direction?: string }) =>
        index.url(undefined, {
            query: {
                date: next.date ?? date,
                direction: next.direction ?? direction,
            },
        });

    const visit = (next: { date?: string; direction?: string }) =>
        router.get(
            urlFor(next),
            {},
            { preserveScroll: true, preserveState: true },
        );

    const tabs: TabLink[] = TABS.map((tab) => ({
        ...tab,
        href: urlFor({ direction: tab.value }),
    }));

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

    const setTick = (row: Row, ticked: boolean) => setTickAsk({ row, ticked });

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
    const exportDates = useMemo(
        () => exportFilters(date, direction),
        [date, direction],
    );
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
                                {can.columns && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setLayoutDraft(layout)}
                                    >
                                        <Settings2 data-icon="inline-start" />
                                        Columns
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
                            {manage && (
                                <Button
                                    type="button"
                                    className="ml-auto"
                                    onClick={() => setTripOpen(true)}
                                >
                                    <Plus data-icon="inline-start" />
                                    Trip or job
                                </Button>
                            )}
                        </div>

                        <TabLinks
                            tabs={tabs}
                            activeTab={direction}
                            className="flex flex-col gap-4"
                            panelWidth="full"
                            panelClassName="flex flex-col gap-4"
                        >
                            {groups.length === 0 ? (
                                <EmptyState
                                    icon={Ship}
                                    title={`No boats on ${dayLabel(date)}.`}
                                    description="Pick another date or tab to see the boats."
                                    action={
                                        manage ? (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    setTripOpen(true)
                                                }
                                            >
                                                <Plus data-icon="inline-start" />
                                                Trip or job
                                            </Button>
                                        ) : undefined
                                    }
                                />
                            ) : (
                                <div className="flex flex-col gap-6">
                                    {groups.map((group) =>
                                        group.kind === 'jobs' ? (
                                            <BoatJobsSection
                                                key={group.key}
                                                jobs={group.jobs}
                                                alignWithTicks={can.manage}
                                                canTick={can.manage}
                                                canManage={manage}
                                                onEdit={setEditJob}
                                                onDelete={setDeleteJob}
                                            />
                                        ) : (
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
                                                    setDialog({
                                                        mode: 'change',
                                                        group,
                                                    })
                                                }
                                                onAdd={() =>
                                                    setDialog({
                                                        mode: 'add',
                                                        group,
                                                    })
                                                }
                                                onTime={() =>
                                                    setDialog({
                                                        mode: 'time',
                                                        group,
                                                    })
                                                }
                                                onCancel={() =>
                                                    setCancelGroup(group)
                                                }
                                                onUndo={() =>
                                                    router.put(
                                                        cancel.url({
                                                            departure:
                                                                group.departureId!,
                                                        }),
                                                        {
                                                            date,
                                                            cancelled: false,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                                onSetBoat={(row) =>
                                                    setSetBoat({
                                                        row,
                                                        open: true,
                                                    })
                                                }
                                                canSetBoat={manage}
                                            />
                                        ),
                                    )}
                                </div>
                            )}
                        </TabLinks>
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
                    sailingCrews={sailingCrews}
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
                    jobTypes={jobTypes}
                />
            )}

            {editJob && (
                <BoatTripDialog
                    open
                    onOpenChange={(open) => !open && setEditJob(null)}
                    date={date}
                    dateLabel={dayLabel(date)}
                    boats={boats}
                    crew={crew}
                    jobTypes={jobTypes}
                    job={editJob}
                />
            )}

            <AlertDialog
                open={deleteJob !== null}
                onOpenChange={(open) => !open && setDeleteJob(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Delete the {deleteJob ? jobName(deleteJob) : ''}{' '}
                            job?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            It leaves the board and the manifest for{' '}
                            {dayLabel(date)}.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Keep job</AlertDialogCancel>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={deleting}
                            focusableWhenDisabled
                            onClick={() =>
                                deleteJob !== null &&
                                router.delete(
                                    destroyJob.url({ sailing: deleteJob.id }),
                                    {
                                        preserveScroll: true,
                                        onStart: () => setDeleting(true),
                                        onFinish: () => {
                                            setDeleting(false);
                                            setDeleteJob(null);
                                        },
                                    },
                                )
                            }
                        >
                            {deleting && <Spinner data-icon="inline-start" />}
                            Delete job
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

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
                            {cancelGroup !== null &&
                                cancelGroup.departureBoats > 1 &&
                                `All ${cancelGroup.departureBoats} boats of this time stop. `}
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

            <BoatTickDialog ask={tickAsk} onClose={() => setTickAsk(null)} />

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
                    boats={{
                        picks: boatPicks,
                        boatKey: setBoat.row.sailing_key,
                    }}
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
        short: row.departure,
    };
}

/**
 * The empty body of a group that keeps its table header: a boat with no guests, or no charter or own boat trips.
 */
function GroupEmpty({ group }: { group: Group }) {
    if (group.kind === 'charter' || group.kind === 'own') {
        return (
            <EmptyState
                icon={Sailboat}
                title={
                    group.kind === 'charter'
                        ? 'No charter trips.'
                        : 'No own boat trips.'
                }
                description="Set Charter or Own boat on a guest with Change boat in the row menu."
            />
        );
    }

    return (
        <EmptyState
            icon={Users}
            title="No guests on this boat."
            description="Move guests here with Move to, or pick this boat in Change boat."
        />
    );
}

/**
 * The second line of a sailing header: "Boat 1 · In charge: Din · Crew: Razak". Plain words, so staff need not guess
 * a symbol. "No boat or crew set" when neither is.
 */
function crewText(group: Group): string {
    if (
        group.boat === null &&
        group.inCharge === null &&
        group.assistants.length === 0
    ) {
        return 'No boat or crew set';
    }

    return [
        group.boat ?? 'No boat',
        group.inCharge ? `In charge: ${group.inCharge}` : null,
        group.assistants.length > 0
            ? `Crew: ${group.assistants.join(', ')}`
            : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

/** "Cancel the 06:00 trip", with "(all boats)" when the departure runs more than one boat that date. */
function cancelLabel(group: Group): string {
    return `Cancel the ${group.time} trip${group.departureBoats > 1 ? ' (all boats)' : ''}`;
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
                            <Ship />
                            Change boat and crew
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={onTime}>
                            <Clock />
                            Change time
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={onAdd}>
                            <Plus />
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
                        <Trash2 />
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
                        <RotateCcw />
                        Reset to timetable
                    </DropdownMenuItem>
                )}
                {group.isMain && !group.isExtra && (
                    <DropdownMenuItem
                        variant="destructive"
                        disabled={group.departureGuests > 0}
                        onClick={onCancel}
                    >
                        <Ban className="mt-0.5 self-start" />
                        <span className="flex flex-col">
                            {cancelLabel(group)}
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
    const columnCount =
        (canSelect ? 1 : 0) + 7 + (isMixed ? 1 : 0) + (hasMenu ? 1 : 0);
    const crewLine = crewText(group);

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
            <div className="flex items-start gap-3 bg-muted/50 px-3 py-2">
                {(canSelect || isNotSet) && (
                    <span className="flex h-5 w-4 shrink-0 items-center">
                        {isNotSet ? (
                            <TriangleAlert
                                aria-hidden
                                className="size-4 text-warning"
                            />
                        ) : (
                            <Checkbox
                                aria-label={`Select all rows in ${group.title}`}
                                checked={
                                    selectable.length > 0 &&
                                    pickedCount === selectable.length
                                }
                                indeterminate={
                                    pickedCount > 0 &&
                                    pickedCount < selectable.length
                                }
                                disabled={selectable.length === 0}
                                onCheckedChange={(on) =>
                                    onSelect(selectable, on)
                                }
                            />
                        )}
                    </span>
                )}
                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <div className="flex flex-wrap items-baseline gap-x-3">
                        <h2 className="text-sm font-medium">
                            {isSailing
                                ? `${group.directionLabel} · ${group.leavesAt} → ${group.arrivesAt}${group.timeChanged ? ' (changed)' : ''}`
                                : group.title}
                        </h2>
                        {isSailing && group.pickupPoint && (
                            <span className="text-sm text-muted-foreground">
                                {group.pickupPoint}
                            </span>
                        )}
                    </div>
                    {isSailing && (
                        <p className="text-sm text-muted-foreground">
                            {group.isExtra ? 'Extra trip · ' : ''}
                            {crewLine}
                        </p>
                    )}
                </div>
                {isMixed && (
                    <span className="shrink-0 text-sm text-muted-foreground">
                        {group.rows.length === 1
                            ? '1 room'
                            : `${group.rows.length} rooms`}{' '}
                        · {pax} pax
                    </span>
                )}
                {isSailing && (
                    <span className="flex shrink-0 items-center gap-1">
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

            <>
                <div className="hidden md:block">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                {canSelect && <TableHead className="w-8" />}
                                <TableHead>Room</TableHead>
                                <TableHead>Lead guest</TableHead>
                                <TableHead>Booking source</TableHead>
                                <TableHead>Phone</TableHead>
                                {isMixed && <TableHead>Trip</TableHead>}
                                <TableHead>Pax</TableHead>
                                <TableHead>Note</TableHead>
                                <TableHead className="text-right">
                                    {isNotSet
                                        ? ''
                                        : isMixed
                                          ? 'Done'
                                          : tickLabel(group.direction)}
                                </TableHead>
                                {hasMenu && (
                                    <TableHead className="w-10">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {group.rows.length === 0 && (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell colSpan={columnCount}>
                                        <GroupEmpty group={group} />
                                    </TableCell>
                                </TableRow>
                            )}
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
                                                        onSelect([row.key], on)
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
                                        <TableCell>{tripText(row)}</TableCell>
                                    )}
                                    <TableCell>
                                        <PaxIcons row={row} />
                                    </TableCell>
                                    <TableCell className="min-w-48">
                                        {!row.not_set && (
                                            <BoatNoteField
                                                row={row}
                                                canEdit={canTick}
                                            />
                                        )}
                                    </TableCell>
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
                {group.rows.length === 0 && (
                    <div className="md:hidden">
                        <GroupEmpty group={group} />
                    </div>
                )}
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
                                <span className="flex min-w-0 items-center gap-2 text-sm font-medium">
                                    <span className="truncate">
                                        {row.unit} · {row.lead_guest}
                                    </span>
                                    <PaxIcons row={row} />
                                </span>
                                <span className="truncate text-xs text-muted-foreground">
                                    {[
                                        isMixed ? tripText(row) : null,
                                        row.agent,
                                        row.phone,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                                {!row.not_set && (
                                    <BoatNoteField
                                        row={row}
                                        canEdit={canTick}
                                        className="mt-1 text-xs"
                                    />
                                )}
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
        </section>
    );
}

/** Charter and Own boat rows mix both directions, so the Trip column says which: "In · 10:30". The note has its own column. */
function tripText(row: Row): string {
    return [row.direction === 'in' ? 'In' : 'Out', row.unit_time]
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
                        <Ship />
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
                        <ArrowUpRight />
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

    const word = tickWord(row.direction);
    const done = word === 'left' ? 'Left' : 'Arrived';

    if (row.ticked) {
        return (
            <span className="inline-flex items-center justify-end gap-2">
                <span className="inline-flex items-center gap-1 text-sm text-success">
                    <Check aria-hidden className="size-4" />
                    {done}
                </span>
                {canTick && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        aria-label={`Undo ${word} for ${row.lead_guest}`}
                        onClick={() => onTick(row, false)}
                    >
                        <Undo2 data-icon="inline-start" />
                        Undo
                    </Button>
                )}
            </span>
        );
    }

    return canTick ? (
        <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => onTick(row, true)}
        >
            <Check data-icon="inline-start" />
            Mark {word}
        </Button>
    ) : (
        <span className="text-sm text-muted-foreground">—</span>
    );
}
