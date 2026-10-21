import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, RefreshCw, Save, TriangleAlert } from 'lucide-react';
import { Fragment, useState } from 'react';
import { FormActions } from '@/components/pms/form-actions';
import { PageHeader } from '@/components/pms/page-header';
import { Stepper } from '@/components/pms/stepper';
import { Workspace } from '@/components/pms/workspace';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes/admin';
import {
    index,
    channels as channelsTab,
    show,
} from '@/routes/admin/properties';
import { update } from '@/routes/admin/properties/channels/connections';

type MapData = App.Data.Channels.ChannelMapPageData;
type Row = App.Data.Channels.ChannelMapRowData;
type Option = App.Data.Channels.ChannelEstatellOptionData;

const NONE = '__none__';
const STEPS = ['Connect', 'Map', 'Activate'];

const groupOf = (row: Row) => `${row.roomCode}|${row.rateCode}`;

/**
 * Each OTA room and rate that has mapped lines needs exactly one primary line. A group with none gets its first mapped
 * line, which is the highest guest count because the rows come in that order. The server applies the same rule.
 */
function withPrimaries(rows: Row[]): Row[] {
    const primary = new Set(
        rows
            .filter((row) => row.estatell && row.isPrimary)
            .map((row) => groupOf(row)),
    );

    return rows.map((row) => {
        if (!row.estatell) {
            return { ...row, isPrimary: false };
        }

        if (primary.has(groupOf(row))) {
            return row;
        }

        primary.add(groupOf(row));

        return { ...row, isPrimary: true };
    });
}

const normalize = (text: string) =>
    text.toLowerCase().replace(/[^a-z0-9]+/g, '');

/** The Estatell room type of each OTA room: from a saved line, else a room type with the same name. */
function initialRooms(rows: Row[], options: Option[]): Record<string, string> {
    const rooms: Record<string, string> = {};

    for (const row of rows) {
        if (rooms[row.roomCode]) {
            continue;
        }

        const own = options.find((option) => option.value === row.estatell);
        const named = options.find(
            (option) => normalize(option.roomType) === normalize(row.roomTitle),
        );
        const found = own ?? named;

        if (found) {
            rooms[row.roomCode] = String(found.roomTypeId);
        }
    }

    return rooms;
}

/** The plans a line can take: of the chosen room type. A plan sold per room takes any guest count, one sold per person only a count it has. */
function choicesFor(
    row: Row,
    options: Option[],
    roomTypeId: string | undefined,
): Option[] {
    return options.filter(
        (option) =>
            String(option.roomTypeId) === roomTypeId &&
            (row.occupancy === null ||
                option.perRoom ||
                option.occupancies.includes(row.occupancy)),
    );
}

export default function ChannelMap({
    property,
    map,
}: {
    property: { code: string; name: string };
    map: MapData;
}) {
    const form = useForm<{ rows: Row[] }>({ rows: map.rows });
    const [fetching, setFetching] = useState(false);
    const [rooms, setRooms] = useState(() =>
        initialRooms(map.rows, map.options),
    );
    const roomItems = [
        { value: NONE, label: 'Select room' },
        ...Array.from(
            new Map(
                map.options.map((option) => [
                    String(option.roomTypeId),
                    option.roomType,
                ]),
            ),
            ([value, label]) => ({ value, label }),
        ),
    ];
    const rows = form.data.rows;
    const mapped = rows.filter((row) => row.estatell !== null);
    const unmapped = rows.filter((row) => row.estatell === null);
    const label = (row: Row) =>
        `${row.roomTitle} · ${row.rateTitle}${row.occupancy ? `, ${row.occupancy} guests` : ''}`;

    const setRows = (next: Row[]) => form.setData('rows', withPrimaries(next));
    const chooseRoom = (roomCode: string, value: string) => {
        setRooms((current) => {
            const next = { ...current };

            if (value === NONE) {
                delete next[roomCode];
            } else {
                next[roomCode] = value;
            }

            return next;
        });
        setRows(
            rows.map((row) =>
                row.roomCode === roomCode &&
                !map.options.some(
                    (option) =>
                        option.value === row.estatell &&
                        String(option.roomTypeId) === value,
                )
                    ? { ...row, estatell: null }
                    : row,
            ),
        );
    };
    const change = (key: string, values: Partial<Row>) =>
        setRows(
            rows.map((row) => (row.key === key ? { ...row, ...values } : row)),
        );
    const makePrimary = (target: Row) =>
        setRows(
            rows.map((row) =>
                groupOf(row) === groupOf(target)
                    ? { ...row, isPrimary: row.key === target.key }
                    : row,
            ),
        );

    return (
        <>
            <Head title={`Map ${map.otaName}`} />

            <form
                className="flex min-h-0 flex-1 flex-col"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform((data) => ({
                        lines: data.rows
                            .filter((row) => row.estatell)
                            .map((row) => {
                                const option = map.options.find(
                                    (item) => item.value === row.estatell,
                                );

                                return {
                                    rate_plan_mapping_id: Number(row.estatell),
                                    occupancy:
                                        row.occupancy ??
                                        Math.max(
                                            ...(option?.occupancies ?? [1]),
                                        ),
                                    ota: `${row.roomCode}|${row.rateCode}`,
                                    is_primary: row.isPrimary,
                                    rate_type: row.rateType,
                                };
                            }),
                    }));
                    form.put(
                        update({
                            property: property.code,
                            connection: map.connectionId,
                        }).url,
                    );
                }}
            >
                <div
                    scroll-region=""
                    className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 lg:overflow-hidden"
                >
                    <PageHeader
                        title={`Map ${map.otaName}`}
                        description={
                            map.hotelId
                                ? `${property.name} · hotel ${map.hotelId}`
                                : property.name
                        }
                        back={{
                            href: channelsTab(property.code),
                            label: 'Channels',
                        }}
                        actions={
                            <Stepper
                                steps={STEPS}
                                current={1}
                                label="Connect steps"
                            />
                        }
                    />

                    <Workspace
                        asideLabel="Mapping summary"
                        aside={
                            <div className="flex flex-col gap-4 p-3 text-sm">
                                <header className="flex flex-col gap-1">
                                    <h2 className="font-heading text-base font-medium">
                                        Mapping
                                    </h2>
                                    <p className="text-muted-foreground">
                                        Rooms and rates from {map.otaName},
                                        fetched just now. Check every line
                                        before you save.
                                    </p>
                                </header>
                                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2">
                                    <dt className="text-muted-foreground">
                                        Hotel ID
                                    </dt>
                                    <dd className="tabular-nums">
                                        {map.hotelId ?? '—'}
                                    </dd>
                                    {map.pricingType && (
                                        <>
                                            <dt className="text-muted-foreground">
                                                Pricing
                                            </dt>
                                            <dd>{map.pricingType}</dd>
                                        </>
                                    )}
                                    <dt className="text-muted-foreground">
                                        Mapped
                                    </dt>
                                    <dd className="tabular-nums">
                                        {mapped.length} of {rows.length}
                                    </dd>
                                </dl>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="self-start"
                                    disabled={fetching}
                                    focusableWhenDisabled
                                    onClick={() =>
                                        router.reload({
                                            only: ['map'],
                                            onStart: () => setFetching(true),
                                            onFinish: () => setFetching(false),
                                        })
                                    }
                                >
                                    {fetching ? (
                                        <Spinner data-icon="inline-start" />
                                    ) : (
                                        <RefreshCw data-icon="inline-start" />
                                    )}
                                    Fetch again
                                </Button>

                                {map.notOnChannels > 0 && (
                                    <p className="text-muted-foreground">
                                        {map.notOnChannels} room type and rate
                                        plan{' '}
                                        {map.notOnChannels === 1
                                            ? 'pair is'
                                            : 'pairs are'}{' '}
                                        not on channels.
                                    </p>
                                )}

                                {unmapped.length > 0 && (
                                    <Alert>
                                        <TriangleAlert />
                                        <AlertDescription>
                                            {unmapped.length} {map.otaName}{' '}
                                            {unmapped.length === 1
                                                ? 'line is'
                                                : 'lines are'}{' '}
                                            not mapped. Unmapped {map.otaName}{' '}
                                            rates cause booking errors. Map each
                                            one, or remove it in the extranet.
                                        </AlertDescription>
                                    </Alert>
                                )}
                            </div>
                        }
                    >
                        <div className="overflow-hidden rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {map.otaName} rate
                                        </TableHead>
                                        <TableHead>Guests</TableHead>
                                        <TableHead>
                                            Estatell room · rate
                                        </TableHead>
                                        {map.usesPrimary && (
                                            <TableHead>Primary</TableHead>
                                        )}
                                        {map.usesType && (
                                            <TableHead>Type</TableHead>
                                        )}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={
                                                    3 +
                                                    Number(map.usesPrimary) +
                                                    Number(map.usesType)
                                                }
                                                className="py-6 text-center text-muted-foreground"
                                            >
                                                {map.otaName} lists no rooms or
                                                rates for this hotel.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {rows.map((row, index) => {
                                        const startsRoom =
                                            index === 0 ||
                                            rows[index - 1].roomCode !==
                                                row.roomCode;
                                        const choices = [
                                            {
                                                value: NONE,
                                                label: 'Not mapped',
                                            },
                                            ...choicesFor(
                                                row,
                                                map.options,
                                                rooms[row.roomCode],
                                            ).map((option) => ({
                                                value: option.value,
                                                label: option.ratePlan,
                                            })),
                                        ];
                                        return (
                                            <Fragment key={row.key}>
                                                {startsRoom && (
                                                    <TableRow className="hover:bg-transparent">
                                                        <TableCell
                                                            colSpan={2}
                                                            className="bg-muted/50 font-medium"
                                                        >
                                                            {row.roomTitle}{' '}
                                                            <span className="font-normal text-muted-foreground">
                                                                ({row.roomCode})
                                                            </span>
                                                        </TableCell>
                                                        <TableCell className="bg-muted/50">
                                                            <Select
                                                                items={
                                                                    roomItems
                                                                }
                                                                value={
                                                                    rooms[
                                                                        row
                                                                            .roomCode
                                                                    ] ?? NONE
                                                                }
                                                                onValueChange={(
                                                                    value,
                                                                ) =>
                                                                    chooseRoom(
                                                                        row.roomCode,
                                                                        value as string,
                                                                    )
                                                                }
                                                            >
                                                                <SelectTrigger
                                                                    aria-label={`Estatell room for ${row.roomTitle}`}
                                                                    className="w-full bg-background"
                                                                >
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectGroup>
                                                                        {roomItems.map(
                                                                            (
                                                                                item,
                                                                            ) => (
                                                                                <SelectItem
                                                                                    key={
                                                                                        item.value
                                                                                    }
                                                                                    value={
                                                                                        item.value
                                                                                    }
                                                                                >
                                                                                    {
                                                                                        item.label
                                                                                    }
                                                                                </SelectItem>
                                                                            ),
                                                                        )}
                                                                    </SelectGroup>
                                                                </SelectContent>
                                                            </Select>
                                                        </TableCell>
                                                        {Number(
                                                            map.usesPrimary,
                                                        ) +
                                                            Number(
                                                                map.usesType,
                                                            ) >
                                                            0 && (
                                                            <TableCell
                                                                colSpan={
                                                                    Number(
                                                                        map.usesPrimary,
                                                                    ) +
                                                                    Number(
                                                                        map.usesType,
                                                                    )
                                                                }
                                                                className="bg-muted/50"
                                                            />
                                                        )}
                                                    </TableRow>
                                                )}
                                                <TableRow>
                                                    <TableCell className="pl-6">
                                                        {row.rateTitle}
                                                    </TableCell>
                                                    <TableCell className="tabular-nums">
                                                        {row.occupancy ?? '—'}
                                                    </TableCell>
                                                    <TableCell className="min-w-64">
                                                        <Select
                                                            disabled={
                                                                !rooms[
                                                                    row.roomCode
                                                                ]
                                                            }
                                                            items={choices}
                                                            value={
                                                                row.estatell ??
                                                                NONE
                                                            }
                                                            onValueChange={(
                                                                value,
                                                            ) =>
                                                                change(
                                                                    row.key,
                                                                    {
                                                                        estatell:
                                                                            value ===
                                                                            NONE
                                                                                ? null
                                                                                : (value as string),
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger
                                                                aria-label={`Estatell room and rate for ${label(row)}`}
                                                                className="w-full"
                                                            >
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectGroup>
                                                                    {choices.map(
                                                                        (
                                                                            choice,
                                                                        ) => (
                                                                            <SelectItem
                                                                                key={
                                                                                    choice.value
                                                                                }
                                                                                value={
                                                                                    choice.value
                                                                                }
                                                                            >
                                                                                {
                                                                                    choice.label
                                                                                }
                                                                            </SelectItem>
                                                                        ),
                                                                    )}
                                                                </SelectGroup>
                                                            </SelectContent>
                                                        </Select>
                                                    </TableCell>
                                                    {map.usesPrimary && (
                                                        <TableCell>
                                                            <Checkbox
                                                                aria-label={`Primary line for ${label(row)}`}
                                                                checked={
                                                                    row.isPrimary
                                                                }
                                                                disabled={
                                                                    row.estatell ===
                                                                    null
                                                                }
                                                                onCheckedChange={() =>
                                                                    makePrimary(
                                                                        row,
                                                                    )
                                                                }
                                                            />
                                                        </TableCell>
                                                    )}
                                                    {map.usesType && (
                                                        <TableCell>
                                                            <Input
                                                                aria-label={`Type for ${label(row)}`}
                                                                value={
                                                                    row.rateType ??
                                                                    ''
                                                                }
                                                                placeholder="e.g. private"
                                                                disabled={
                                                                    row.estatell ===
                                                                    null
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    change(
                                                                        row.key,
                                                                        {
                                                                            rateType:
                                                                                event
                                                                                    .target
                                                                                    .value ||
                                                                                null,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </TableCell>
                                                    )}
                                                </TableRow>
                                            </Fragment>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    </Workspace>
                </div>

                <FormActions fixed>
                    <Link
                        href={channelsTab(property.code)}
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        <ArrowLeft data-icon="inline-start" />
                        Back
                    </Link>
                    <Button
                        type="submit"
                        disabled={form.processing}
                        focusableWhenDisabled
                    >
                        {form.processing ? (
                            <Spinner data-icon="inline-start" />
                        ) : (
                            <Save data-icon="inline-start" />
                        )}
                        Save mapping
                    </Button>
                </FormActions>
            </form>
        </>
    );
}

ChannelMap.layout = (props: {
    property: { code: string; name: string };
    map: MapData;
}) => ({
    variant: 'fixed',
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Properties', href: index() },
        { title: props.property.name, href: show(props.property.code) },
        { title: 'Channels', href: channelsTab(props.property.code) },
        { title: `Map ${props.map.otaName}` },
    ],
});
