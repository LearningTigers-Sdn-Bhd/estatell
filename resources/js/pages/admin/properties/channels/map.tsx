import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, RefreshCw, Save, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
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

const NONE = '__none__';
const STEPS = ['Connect', 'Map', 'Activate'];

/**
 * Each OTA room and rate that has lines needs exactly one primary line. A group with none gets its first line, which is
 * the highest occupancy because the rows come in that order. The server applies the same rule.
 */
function withPrimaries(rows: Row[]): Row[] {
    const primary = new Set(
        rows.filter((row) => row.ota && row.isPrimary).map((row) => row.ota),
    );

    return rows.map((row) => {
        if (!row.ota) {
            return { ...row, isPrimary: false };
        }

        if (primary.has(row.ota)) {
            return row;
        }

        primary.add(row.ota);

        return { ...row, isPrimary: true };
    });
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
    const rows = form.data.rows;
    const mapped = rows.filter((row) => row.ota !== null);
    const selected = new Set(mapped.map((row) => row.ota));
    const unmatched = map.options.filter(
        (option) => !selected.has(option.value),
    );
    const options = [
        { value: NONE, label: 'Not mapped' },
        ...map.options.map((option) => ({
            value: option.value,
            label: option.label,
        })),
    ];

    const setRows = (next: Row[]) => form.setData('rows', withPrimaries(next));
    const change = (key: string, values: Partial<Row>) =>
        setRows(
            rows.map((row) => (row.key === key ? { ...row, ...values } : row)),
        );
    const makePrimary = (target: Row) =>
        setRows(
            rows.map((row) =>
                row.ota === target.ota
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
                        lines: data.rows.map((row) => ({
                            rate_plan_mapping_id: row.ratePlanMappingId,
                            occupancy: row.occupancy,
                            ota: row.ota,
                            is_primary: row.isPrimary,
                            rate_type: row.rateType,
                        })),
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

                                {unmatched.length > 0 && (
                                    <Alert>
                                        <TriangleAlert />
                                        <AlertDescription>
                                            {map.otaName} has {unmatched.length}{' '}
                                            {unmatched.length === 1
                                                ? 'rate'
                                                : 'rates'}{' '}
                                            with no match:{' '}
                                            {unmatched
                                                .slice(0, 6)
                                                .map((option) => option.label)
                                                .join(', ')}
                                            {unmatched.length > 6 ? ', …' : ''}.
                                            Unmapped {map.otaName} rates cause
                                            booking errors. Map each one, or
                                            remove it in the extranet.
                                        </AlertDescription>
                                    </Alert>
                                )}
                            </div>
                        }
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>estatell room · rate</TableHead>
                                    <TableHead>Occ</TableHead>
                                    <TableHead>
                                        {map.otaName} room · rate
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
                                            colSpan={5}
                                            className="py-6 text-center text-muted-foreground"
                                        >
                                            No rate plans are on Channex yet.
                                            Sync the structure first.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {rows.map((row) => (
                                    <TableRow key={row.key}>
                                        <TableCell className="font-medium">
                                            {row.roomType} · {row.ratePlan}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {row.occupancy}
                                        </TableCell>
                                        <TableCell className="min-w-64">
                                            <Select
                                                items={options}
                                                value={row.ota ?? NONE}
                                                onValueChange={(value) =>
                                                    change(row.key, {
                                                        ota:
                                                            value === NONE
                                                                ? null
                                                                : (value as string),
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    aria-label={`${map.otaName} room and rate for ${row.roomType} · ${row.ratePlan}, ${row.occupancy} guests`}
                                                    className="w-full"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectGroup>
                                                        {options.map(
                                                            (option) => (
                                                                <SelectItem
                                                                    key={
                                                                        option.value
                                                                    }
                                                                    value={
                                                                        option.value
                                                                    }
                                                                >
                                                                    {
                                                                        option.label
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
                                                    aria-label={`Primary occupancy for ${row.roomType} · ${row.ratePlan}, ${row.occupancy} guests`}
                                                    checked={row.isPrimary}
                                                    disabled={row.ota === null}
                                                    onCheckedChange={() =>
                                                        makePrimary(row)
                                                    }
                                                />
                                            </TableCell>
                                        )}
                                        {map.usesType && (
                                            <TableCell>
                                                <Input
                                                    aria-label={`Type for ${row.roomType} · ${row.ratePlan}`}
                                                    value={row.rateType ?? ''}
                                                    placeholder="e.g. private"
                                                    disabled={row.ota === null}
                                                    onChange={(event) =>
                                                        change(row.key, {
                                                            rateType:
                                                                event.target
                                                                    .value ||
                                                                null,
                                                        })
                                                    }
                                                />
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
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
