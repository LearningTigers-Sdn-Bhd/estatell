import { router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    KitchenStationForm,
    type KitchenStationRow,
} from '@/components/pms/pos/pos-kitchen-station-form';
import {
    PosSettingsShell,
    posSettingsBreadcrumbs,
} from '@/components/pms/pos/pos-settings-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index as kitchenIndex } from '@/routes/settings/pos/kitchen';
import { update as updateRouting } from '@/routes/settings/pos/kitchen-routing';

type Props = {
    outlets: EnumOption[];
    outlet: { id: number; name: string } | null;
    stations: KitchenStationRow[];
    categories: { id: number; name: string; stationId: number | null }[];
    items: {
        id: number;
        name: string;
        category: string | null;
        stationId: number | null;
        isCombo: boolean;
    }[];
    stationModes: EnumOption[];
    diningKinds: EnumOption[];
};

type Editing = KitchenStationRow | 'new' | null;

export default function PosKitchen({
    outlets,
    outlet,
    stations,
    categories,
    items,
    stationModes,
    diningKinds,
}: Props) {
    const [editing, setEditing] = useState<Editing>(null);

    const stationOptions: EnumOption[] = stations.map((station) => ({
        value: String(station.id),
        label: station.name,
    }));

    const routing = useForm({
        categories: Object.fromEntries(
            categories.map((row) => [
                String(row.id),
                row.stationId === null ? '' : String(row.stationId),
            ]),
        ) as Record<string, string>,
        items: Object.fromEntries(
            items.map((row) => [
                String(row.id),
                row.stationId === null ? '' : String(row.stationId),
            ]),
        ) as Record<string, string>,
    });

    const toPayload = (values: Record<string, string>) =>
        Object.fromEntries(
            Object.entries(values).map(([id, station]) => [
                id,
                station === '' ? null : Number(station),
            ]),
        );

    return (
        <PosSettingsShell activeTab="kitchen">
            <Card>
                <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <CardTitle>Kitchen stations</CardTitle>
                        <CardDescription>
                            Each station is one kitchen screen. Fired dishes
                            show on the screen of their station.
                        </CardDescription>
                    </div>
                    <div className="flex items-center gap-3">
                        {outlets.length > 1 && (
                            <EnumSelect
                                name="outlet"
                                aria-label="Outlet"
                                options={outlets}
                                value={String(outlet?.id ?? '')}
                                onValueChange={(value) =>
                                    router.get(
                                        kitchenIndex.url(undefined, {
                                            query: { outlet: value },
                                        }),
                                    )
                                }
                            />
                        )}
                        {outlet !== null && (
                            <Button onClick={() => setEditing('new')}>
                                <Plus data-icon="inline-start" />
                                Add station
                            </Button>
                        )}
                    </div>
                </CardHeader>
                <CardContent>
                    {outlet === null && (
                        <p className="text-muted-foreground">
                            Enable POS on an outlet first.
                        </p>
                    )}
                    {outlet !== null && (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Mode</TableHead>
                                    <TableHead>Warn / alert</TableHead>
                                    <TableHead>Dining kinds</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {stations.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="text-muted-foreground"
                                        >
                                            No kitchen stations yet. Without
                                            one, fired dishes show on no screen.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {stations.map((station) => (
                                    <TableRow key={station.id}>
                                        <TableCell className="font-medium">
                                            {station.name}
                                        </TableCell>
                                        <TableCell>
                                            {
                                                stationModes.find(
                                                    (mode) =>
                                                        mode.value ===
                                                        station.mode,
                                                )?.label
                                            }
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {station.warnAfterMinutes} /{' '}
                                            {station.alertAfterMinutes} min
                                        </TableCell>
                                        <TableCell>
                                            {station.diningKinds.length === 0
                                                ? 'All'
                                                : station.diningKinds
                                                      .map(
                                                          (kind) =>
                                                              diningKinds.find(
                                                                  (option) =>
                                                                      option.value ===
                                                                      kind,
                                                              )?.label ?? kind,
                                                      )
                                                      .join(', ')}
                                        </TableCell>
                                        <TableCell>
                                            {station.active ? (
                                                <Badge variant="secondary">
                                                    On
                                                </Badge>
                                            ) : (
                                                <Badge variant="outline">
                                                    Off
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-end">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setEditing(station)
                                                }
                                            >
                                                Edit
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            {outlet !== null && stations.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle>Routing</CardTitle>
                        <CardDescription>
                            An item goes to its own station, else to its
                            category&apos;s. Combos route through their parts.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-6">
                        <form
                            className="flex flex-col gap-6"
                            onSubmit={(event) => {
                                event.preventDefault();
                                routing.transform((data) => ({
                                    categories: toPayload(data.categories),
                                    items: toPayload(data.items),
                                }));
                                routing.put(
                                    updateRouting.url({ outlet: outlet.id }),
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            <div className="flex flex-col gap-2">
                                <h3 className="font-medium">Categories</h3>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Category</TableHead>
                                            <TableHead className="w-64">
                                                Station
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {categories.map((category) => (
                                            <TableRow key={category.id}>
                                                <TableCell>
                                                    {category.name}
                                                </TableCell>
                                                <TableCell className="w-64">
                                                    <EnumSelect
                                                        name={`category_station_${category.id}`}
                                                        aria-label={`${category.name} station`}
                                                        allLabel="Not routed"
                                                        options={stationOptions}
                                                        value={
                                                            routing.data
                                                                .categories[
                                                                String(
                                                                    category.id,
                                                                )
                                                            ] ?? ''
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            routing.setData(
                                                                'categories',
                                                                {
                                                                    ...routing
                                                                        .data
                                                                        .categories,
                                                                    [String(
                                                                        category.id,
                                                                    )]: value,
                                                                },
                                                            )
                                                        }
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                            <div className="flex flex-col gap-2">
                                <h3 className="font-medium">Items</h3>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Item</TableHead>
                                            <TableHead className="w-64">
                                                Station override
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {items.map((item) => (
                                            <TableRow key={item.id}>
                                                <TableCell>
                                                    {item.name}
                                                    {item.category !== null && (
                                                        <span className="ms-2 text-sm text-muted-foreground">
                                                            {item.category}
                                                        </span>
                                                    )}
                                                    {item.isCombo && (
                                                        <Badge
                                                            variant="outline"
                                                            className="ms-2"
                                                        >
                                                            Combo
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                                <TableCell className="w-64">
                                                    <EnumSelect
                                                        name={`item_station_${item.id}`}
                                                        aria-label={`${item.name} station`}
                                                        allLabel="Use category"
                                                        options={stationOptions}
                                                        value={
                                                            routing.data.items[
                                                                String(item.id)
                                                            ] ?? ''
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            routing.setData(
                                                                'items',
                                                                {
                                                                    ...routing
                                                                        .data
                                                                        .items,
                                                                    [String(
                                                                        item.id,
                                                                    )]: value,
                                                                },
                                                            )
                                                        }
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                            <div className="flex justify-end">
                                <Button
                                    type="submit"
                                    disabled={routing.processing}
                                    focusableWhenDisabled
                                >
                                    {routing.processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Save routing
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            )}

            {editing !== null && outlet !== null && (
                <KitchenStationForm
                    key={editing === 'new' ? 'new' : editing.id}
                    outletId={outlet.id}
                    row={editing === 'new' ? null : editing}
                    modes={stationModes}
                    diningKinds={diningKinds}
                    onClose={() => setEditing(null)}
                />
            )}
        </PosSettingsShell>
    );
}

PosKitchen.layout = {
    breadcrumbs: posSettingsBreadcrumbs('kitchen'),
};
