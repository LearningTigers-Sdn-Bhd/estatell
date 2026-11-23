import { Head, router } from '@inertiajs/react';
import { Search, Utensils } from 'lucide-react';
import { useMemo, useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { destroy, index, store } from '@/routes/restaurant/meal-check-in';

type Row = App.Data.Pos.MealCheckInRow;
type List = App.Data.Pos.MealCheckInListData;

const tabs = [
    { value: 'all', label: 'All' },
    { value: 'pending', label: 'Pending' },
    { value: 'partial', label: 'Partial' },
    { value: 'redeemed', label: 'Redeemed' },
];

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <Card>
            <CardContent className="flex flex-col gap-1">
                <p className="text-sm text-muted-foreground">{label}</p>
                <p className="font-heading text-3xl tabular-nums">{value}</p>
            </CardContent>
        </Card>
    );
}

/** Pick how many of the remaining guests arrived. */
function PartialPopover({
    row,
    onCheckIn,
}: {
    row: Row;
    onCheckIn: (row: Row, guests: number) => void;
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
                        disabled={row.remainingGuests < 2}
                    />
                }
            >
                Partial
            </PopoverTrigger>
            <PopoverContent className="flex w-56 flex-col gap-2">
                <p className="text-sm text-muted-foreground">
                    How many guests arrived?
                </p>
                <div className="flex flex-wrap gap-2">
                    {Array.from(
                        { length: row.remainingGuests - 1 },
                        (_, position) => position + 1,
                    ).map((count) => (
                        <Button
                            key={count}
                            type="button"
                            size="sm"
                            variant="outline"
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

export default function MealCheckIn({
    date,
    periods,
    periodId,
    list,
}: {
    date: string | null;
    periods: { value: string; label: string }[];
    periodId: number | null;
    list: List | null;
}) {
    const [search, setSearch] = useState('');
    const [tab, setTab] = useState('all');

    const rows = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return (list?.rows ?? []).filter(
            (row) =>
                (tab === 'all' || row.status === tab) &&
                (needle === '' ||
                    `${row.roomNumber} ${row.guestName} ${row.reservationReference}`
                        .toLowerCase()
                        .includes(needle)),
        );
    }, [list, search, tab]);

    function checkIn(row: Row, guests: number) {
        router.post(
            store.url(),
            {
                reservation_unit_id: row.reservationUnitId,
                meal_period_id: periodId,
                service_date: date,
                guests,
            },
            { preserveScroll: true },
        );
    }

    function undo(row: Row) {
        const last = row.redemptionIds[0];

        if (last !== undefined) {
            router.delete(destroy.url({ redemption: last }), {
                preserveScroll: true,
            });
        }
    }

    return (
        <>
            <Head title="Meal check-in" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Meal check-in"
                    description={
                        date ? (
                            <>
                                Guests with a meal included, for{' '}
                                <DateText date={date} />.
                            </>
                        ) : (
                            'This property has no open business date.'
                        )
                    }
                    actions={
                        periods.length > 0 ? (
                            <EnumSelect
                                options={periods}
                                value={
                                    periodId === null ? '' : String(periodId)
                                }
                                onValueChange={(period) =>
                                    router.get(
                                        index.url(undefined, {
                                            query: { period },
                                        }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                aria-label="Meal period"
                                className="w-44"
                            />
                        ) : undefined
                    }
                />

                {list === null ? (
                    <EmptyState
                        icon={Utensils}
                        title="No meal period to show"
                        description="Add an active meal period under Settings, and make sure the property has an open business date."
                    />
                ) : (
                    <>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Stat label="Entitled" value={list.totalEntitled} />
                            <Stat label="Checked in" value={list.checkedIn} />
                            <Stat label="Remaining" value={list.remaining} />
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <InputGroup className="w-full sm:w-72">
                                <InputGroupAddon>
                                    <Search />
                                </InputGroupAddon>
                                <InputGroupInput
                                    aria-label="Search"
                                    placeholder="Room or guest"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                />
                            </InputGroup>
                            <Tabs value={tab} onValueChange={setTab}>
                                <TabsList>
                                    {tabs.map((item) => (
                                        <TabsTrigger
                                            key={item.value}
                                            value={item.value}
                                        >
                                            {item.label}
                                        </TabsTrigger>
                                    ))}
                                </TabsList>
                            </Tabs>
                        </div>

                        {rows.length === 0 ? (
                            <EmptyState
                                icon={Utensils}
                                title="No rooms to show"
                                description="No in-house room matches, or no booking includes this meal today."
                            />
                        ) : (
                            <Card>
                                <CardContent>
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Room</TableHead>
                                                <TableHead>Guest</TableHead>
                                                <TableHead className="text-right">
                                                    Entitled
                                                </TableHead>
                                                <TableHead className="text-right">
                                                    Redeemed
                                                </TableHead>
                                                <TableHead className="text-right">
                                                    Remaining
                                                </TableHead>
                                                <TableHead>
                                                    <span className="sr-only">
                                                        Check in
                                                    </span>
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {rows.map((row) => (
                                                <TableRow
                                                    key={row.reservationUnitId}
                                                >
                                                    <TableCell className="font-medium">
                                                        {row.roomNumber}
                                                    </TableCell>
                                                    <TableCell>
                                                        {row.guestName}
                                                        <span className="block text-xs text-muted-foreground">
                                                            {
                                                                row.reservationReference
                                                            }
                                                        </span>
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {row.entitledGuests}
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {row.redeemedGuests}
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {row.remainingGuests}
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex items-center justify-end gap-2">
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                disabled={
                                                                    row.remainingGuests <
                                                                    1
                                                                }
                                                                onClick={() =>
                                                                    checkIn(
                                                                        row,
                                                                        row.remainingGuests,
                                                                    )
                                                                }
                                                            >
                                                                Check in all
                                                            </Button>
                                                            <PartialPopover
                                                                row={row}
                                                                onCheckIn={
                                                                    checkIn
                                                                }
                                                            />
                                                            <DropdownMenu>
                                                                <DropdownMenuTrigger
                                                                    render={
                                                                        <Button
                                                                            type="button"
                                                                            variant="ghost"
                                                                            size="sm"
                                                                            aria-label={`More for room ${row.roomNumber}`}
                                                                            disabled={
                                                                                row
                                                                                    .redemptionIds
                                                                                    .length ===
                                                                                0
                                                                            }
                                                                        />
                                                                    }
                                                                >
                                                                    More
                                                                </DropdownMenuTrigger>
                                                                <DropdownMenuContent align="end">
                                                                    <DropdownMenuItem
                                                                        onClick={() =>
                                                                            undo(
                                                                                row,
                                                                            )
                                                                        }
                                                                    >
                                                                        Undo
                                                                        last
                                                                        check-in
                                                                    </DropdownMenuItem>
                                                                </DropdownMenuContent>
                                                            </DropdownMenu>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </CardContent>
                            </Card>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

MealCheckIn.layout = {
    breadcrumbs: [{ title: 'Meal check-in', href: index() }],
};
