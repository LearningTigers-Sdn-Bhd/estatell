import { router, useForm } from '@inertiajs/react';
import {
    ChefHat,
    Monitor,
    Plus,
    Printer,
    Search,
    Store,
    X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
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
import { StatusBadge } from '@/components/pms/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
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

const MODE_ICONS: Record<string, LucideIcon> = {
    kds: Monitor,
    browser_print: Printer,
    posport_printer: Printer,
    both: Monitor,
};

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
    const [routingTab, setRoutingTab] = useState<'categories' | 'items'>(
        'categories',
    );
    const [routingSearch, setRoutingSearch] = useState('');

    const stationOptions = useMemo<EnumOption[]>(
        () =>
            stations.map((station) => ({
                value: String(station.id),
                label: station.name,
            })),
        [stations],
    );

    const stationNameById = useMemo(
        () =>
            new Map(
                stations.map((station) => [String(station.id), station.name]),
            ),
        [stations],
    );

    const modeLabels = useMemo(
        () => new Map(stationModes.map((mode) => [mode.value, mode.label])),
        [stationModes],
    );

    const diningKindLabels = useMemo(
        () => new Map(diningKinds.map((kind) => [kind.value, kind.label])),
        [diningKinds],
    );

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

    const categoryStationByName = useMemo(() => {
        const map = new Map<string, string>();

        for (const category of categories) {
            const stationId = routing.data.categories[String(category.id)];

            if (stationId) {
                const stationName = stationNameById.get(stationId);

                if (stationName) {
                    map.set(category.name, stationName);
                }
            }
        }

        return map;
    }, [categories, routing.data.categories, stationNameById]);

    const routedCategoryCount = useMemo(
        () =>
            Object.values(routing.data.categories).filter((id) => id !== '')
                .length,
        [routing.data.categories],
    );

    const routedItemCount = useMemo(
        () =>
            Object.values(routing.data.items).filter((id) => id !== '').length,
        [routing.data.items],
    );

    const normalizedSearch = routingSearch.trim().toLocaleLowerCase();

    const filteredCategories = useMemo(
        () =>
            normalizedSearch === ''
                ? categories
                : categories.filter((category) =>
                      category.name
                          .toLocaleLowerCase()
                          .includes(normalizedSearch),
                  ),
        [categories, normalizedSearch],
    );

    const filteredItems = useMemo(
        () =>
            normalizedSearch === ''
                ? items
                : items.filter((item) =>
                      `${item.name} ${item.category ?? ''}`
                          .toLocaleLowerCase()
                          .includes(normalizedSearch),
                  ),
        [items, normalizedSearch],
    );

    const activeStationCount = stations.filter(
        (station) => station.active,
    ).length;

    const toPayload = (values: Record<string, string>) =>
        Object.fromEntries(
            Object.entries(values).map(([id, station]) => [
                id,
                station === '' ? null : Number(station),
            ]),
        );

    const headerActions = (
        <div className="flex flex-wrap items-center justify-end gap-2">
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
                    icon={<Store aria-hidden />}
                    className="w-48"
                />
            )}
            <Button
                type="button"
                className="max-sm:min-h-11"
                disabled={outlet === null}
                onClick={() => setEditing('new')}
            >
                <Plus data-icon="inline-start" />
                Add station
            </Button>
        </div>
    );

    return (
        <PosSettingsShell activeTab="kitchen" actions={headerActions}>
            {outlet === null ? (
                <div className="rounded-xl border bg-card p-6">
                    <EmptyState
                        icon={Store}
                        title="No POS outlet available"
                        description="Enable built-in POS on at least one outlet under Setup to configure kitchen stations."
                    />
                </div>
            ) : (
                <div className="flex flex-col gap-6">
                    <section className="overflow-hidden rounded-xl border bg-card">
                        <header className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/40 px-4 py-3.5">
                            <div className="flex min-w-0 flex-col gap-0.5">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2 className="font-heading text-base font-medium">
                                        Stations
                                    </h2>
                                    {stations.length > 0 && (
                                        <Badge variant="outline">
                                            <span className="tabular-nums">
                                                {activeStationCount} active of{' '}
                                                {stations.length}
                                            </span>
                                        </Badge>
                                    )}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Screens and printers for {outlet.name}.
                                    Fired dishes appear on the station they are
                                    routed to.
                                </p>
                            </div>
                        </header>

                        {stations.length === 0 ? (
                            <div className="p-6">
                                <EmptyState
                                    icon={ChefHat}
                                    title="No kitchen stations yet"
                                    description="Use Add station above so fired dishes appear on a kitchen screen or docket printer."
                                />
                            </div>
                        ) : (
                            <>
                                <div className="hidden md:block">
                                    <Table>
                                        <TableHeader>
                                            <TableRow className="hover:bg-transparent">
                                                <TableHead scope="col">
                                                    Station
                                                </TableHead>
                                                <TableHead scope="col">
                                                    Mode
                                                </TableHead>
                                                <TableHead scope="col">
                                                    Thresholds
                                                </TableHead>
                                                <TableHead scope="col">
                                                    Dining kinds
                                                </TableHead>
                                                <TableHead scope="col">
                                                    Status
                                                </TableHead>
                                                <TableHead
                                                    scope="col"
                                                    className="w-24 text-right"
                                                >
                                                    <span className="sr-only">
                                                        Actions
                                                    </span>
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {stations.map((station) => {
                                                const ModeIcon =
                                                    MODE_ICONS[station.mode] ??
                                                    Monitor;
                                                const kindSummary =
                                                    station.diningKinds
                                                        .length === 0
                                                        ? 'All kinds'
                                                        : station.diningKinds
                                                              .map(
                                                                  (kind) =>
                                                                      diningKindLabels.get(
                                                                          kind,
                                                                      ) ?? kind,
                                                              )
                                                              .join(', ');

                                                return (
                                                    <TableRow key={station.id}>
                                                        <TableCell>
                                                            <div className="flex flex-col gap-0.5">
                                                                <span className="font-medium text-foreground">
                                                                    {
                                                                        station.name
                                                                    }
                                                                </span>
                                                                {station.useInternalName && (
                                                                    <span className="text-xs text-muted-foreground">
                                                                        Shows
                                                                        kitchen
                                                                        name
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </TableCell>
                                                        <TableCell>
                                                            <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                                                <ModeIcon
                                                                    aria-hidden
                                                                    className="size-3.5 shrink-0"
                                                                />
                                                                <span className="text-foreground">
                                                                    {modeLabels.get(
                                                                        station.mode,
                                                                    ) ??
                                                                        station.mode}
                                                                </span>
                                                            </span>
                                                        </TableCell>
                                                        <TableCell className="tabular-nums">
                                                            Warn{' '}
                                                            {
                                                                station.warnAfterMinutes
                                                            }
                                                            m · Alert{' '}
                                                            {
                                                                station.alertAfterMinutes
                                                            }
                                                            m
                                                        </TableCell>
                                                        <TableCell className="max-w-xs whitespace-normal text-muted-foreground">
                                                            {kindSummary}
                                                        </TableCell>
                                                        <TableCell>
                                                            <StatusBadge
                                                                status={{
                                                                    value: station.active
                                                                        ? 'active'
                                                                        : 'inactive',
                                                                    label: station.active
                                                                        ? 'Active'
                                                                        : 'Inactive',
                                                                    tone: station.active
                                                                        ? 'success'
                                                                        : 'neutral',
                                                                }}
                                                            />
                                                        </TableCell>
                                                        <TableCell>
                                                            <div className="flex justify-end">
                                                                <Button
                                                                    type="button"
                                                                    variant="outline"
                                                                    size="sm"
                                                                    aria-label={`Edit kitchen station ${station.name}`}
                                                                    onClick={() =>
                                                                        setEditing(
                                                                            station,
                                                                        )
                                                                    }
                                                                >
                                                                    Edit
                                                                </Button>
                                                            </div>
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            })}
                                        </TableBody>
                                    </Table>
                                </div>

                                <div className="flex flex-col divide-y md:hidden">
                                    {stations.map((station) => {
                                        const ModeIcon =
                                            MODE_ICONS[station.mode] ?? Monitor;
                                        const kindSummary =
                                            station.diningKinds.length === 0
                                                ? 'All kinds'
                                                : station.diningKinds
                                                      .map(
                                                          (kind) =>
                                                              diningKindLabels.get(
                                                                  kind,
                                                              ) ?? kind,
                                                      )
                                                      .join(', ');

                                        return (
                                            <div
                                                key={station.id}
                                                className="flex flex-col gap-3 p-4"
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="flex min-w-0 flex-col gap-1">
                                                        <span className="font-medium text-foreground">
                                                            {station.name}
                                                        </span>
                                                        <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                                                            <ModeIcon
                                                                aria-hidden
                                                                className="size-3.5 shrink-0"
                                                            />
                                                            <span>
                                                                {modeLabels.get(
                                                                    station.mode,
                                                                ) ??
                                                                    station.mode}
                                                            </span>
                                                        </span>
                                                    </div>
                                                    <StatusBadge
                                                        status={{
                                                            value: station.active
                                                                ? 'active'
                                                                : 'inactive',
                                                            label: station.active
                                                                ? 'Active'
                                                                : 'Inactive',
                                                            tone: station.active
                                                                ? 'success'
                                                                : 'neutral',
                                                        }}
                                                    />
                                                </div>
                                                <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground tabular-nums">
                                                    <span>
                                                        Warn{' '}
                                                        {
                                                            station.warnAfterMinutes
                                                        }
                                                        m · Alert{' '}
                                                        {
                                                            station.alertAfterMinutes
                                                        }
                                                        m
                                                    </span>
                                                    <span>{kindSummary}</span>
                                                    {station.useInternalName && (
                                                        <span>
                                                            Shows kitchen name
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="flex justify-end">
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        className="min-h-11"
                                                        aria-label={`Edit kitchen station ${station.name}`}
                                                        onClick={() =>
                                                            setEditing(station)
                                                        }
                                                    >
                                                        Edit
                                                    </Button>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            </>
                        )}
                    </section>

                    {stations.length > 0 && (
                        <section className="overflow-hidden rounded-xl border bg-card">
                            <header className="flex flex-wrap items-center justify-between gap-3 border-b bg-muted/40 px-4 py-3.5">
                                <div className="flex min-w-0 flex-col gap-0.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="font-heading text-base font-medium">
                                            Routing
                                        </h2>
                                        <Badge variant="outline">
                                            <span className="tabular-nums">
                                                {routedCategoryCount} of{' '}
                                                {categories.length} categories ·{' '}
                                                {routedItemCount}{' '}
                                                {routedItemCount === 1
                                                    ? 'override'
                                                    : 'overrides'}
                                            </span>
                                        </Badge>
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        Items inherit their category&apos;s
                                        station unless given an override. Combos
                                        route through their parts.
                                    </p>
                                </div>
                            </header>

                            <form
                                className="flex flex-col"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    routing.transform((data) => ({
                                        categories: toPayload(data.categories),
                                        items: toPayload(data.items),
                                    }));
                                    routing.put(
                                        updateRouting.url({
                                            outlet: outlet.id,
                                        }),
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                <div className="flex flex-col gap-3 border-b px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                    <Tabs
                                        value={routingTab}
                                        onValueChange={(value) =>
                                            setRoutingTab(
                                                value as 'categories' | 'items',
                                            )
                                        }
                                    >
                                        <TabsList>
                                            <TabsTrigger
                                                value="categories"
                                                className="gap-1.5 tabular-nums"
                                            >
                                                <span>Categories</span>
                                                <span className="text-xs text-muted-foreground">
                                                    {categories.length}
                                                </span>
                                            </TabsTrigger>
                                            <TabsTrigger
                                                value="items"
                                                className="gap-1.5 tabular-nums"
                                            >
                                                <span>Item overrides</span>
                                                <span className="text-xs text-muted-foreground">
                                                    {items.length}
                                                </span>
                                            </TabsTrigger>
                                        </TabsList>
                                    </Tabs>

                                    <InputGroup className="w-full sm:max-w-xs">
                                        <InputGroupAddon>
                                            <Search aria-hidden />
                                        </InputGroupAddon>
                                        <InputGroupInput
                                            type="search"
                                            value={routingSearch}
                                            onChange={(event) =>
                                                setRoutingSearch(
                                                    event.target.value,
                                                )
                                            }
                                            placeholder={
                                                routingTab === 'categories'
                                                    ? 'Filter categories'
                                                    : 'Filter items or category'
                                            }
                                            aria-label="Filter routing targets"
                                        />
                                        {routingSearch !== '' && (
                                            <InputGroupAddon align="inline-end">
                                                <InputGroupButton
                                                    size="icon-xs"
                                                    aria-label="Clear filter"
                                                    onClick={() =>
                                                        setRoutingSearch('')
                                                    }
                                                >
                                                    <X />
                                                </InputGroupButton>
                                            </InputGroupAddon>
                                        )}
                                    </InputGroup>
                                </div>

                                {routingTab === 'categories' ? (
                                    filteredCategories.length === 0 ? (
                                        <div className="p-6">
                                            <EmptyState
                                                icon={Search}
                                                title={
                                                    categories.length === 0
                                                        ? 'No menu categories yet'
                                                        : 'No matching categories'
                                                }
                                                description={
                                                    categories.length === 0
                                                        ? 'Create categories on the Menu page to assign default kitchen stations.'
                                                        : 'No category matches your search filter.'
                                                }
                                                action={
                                                    routingSearch !== '' ? (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() =>
                                                                setRoutingSearch(
                                                                    '',
                                                                )
                                                            }
                                                        >
                                                            Clear filter
                                                        </Button>
                                                    ) : undefined
                                                }
                                            />
                                        </div>
                                    ) : (
                                        <div className="divide-y">
                                            {filteredCategories.map(
                                                (category) => (
                                                    <div
                                                        key={category.id}
                                                        className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                                                    >
                                                        <span className="font-medium text-foreground">
                                                            {category.name}
                                                        </span>
                                                        <div className="flex justify-end">
                                                            <EnumSelect
                                                                name={`category_station_${category.id}`}
                                                                aria-label={`${category.name} station`}
                                                                allLabel="Not routed"
                                                                options={
                                                                    stationOptions
                                                                }
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
                                                                            )]:
                                                                                value,
                                                                        },
                                                                    )
                                                                }
                                                                className="w-full sm:w-56"
                                                            />
                                                        </div>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    )
                                ) : filteredItems.length === 0 ? (
                                    <div className="p-6">
                                        <EmptyState
                                            icon={Search}
                                            title={
                                                items.length === 0
                                                    ? 'No menu items yet'
                                                    : 'No matching items'
                                            }
                                            description={
                                                items.length === 0
                                                    ? 'Add items on the Menu page to set per-item station overrides.'
                                                    : 'No item matches your search filter.'
                                            }
                                            action={
                                                routingSearch !== '' ? (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            setRoutingSearch('')
                                                        }
                                                    >
                                                        Clear filter
                                                    </Button>
                                                ) : undefined
                                            }
                                        />
                                    </div>
                                ) : (
                                    <div className="divide-y">
                                        {filteredItems.map((item) => {
                                            const inherited = item.category
                                                ? categoryStationByName.get(
                                                      item.category,
                                                  )
                                                : undefined;

                                            return (
                                                <div
                                                    key={item.id}
                                                    className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                                                >
                                                    <div className="flex min-w-0 flex-col gap-0.5">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span className="font-medium text-foreground">
                                                                {item.name}
                                                            </span>
                                                            {item.isCombo && (
                                                                <Badge variant="outline">
                                                                    Combo
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        <span className="text-xs text-muted-foreground">
                                                            {item.category ??
                                                                'Uncategorized'}
                                                            {inherited
                                                                ? ` · Inherits ${inherited}`
                                                                : ' · Category not routed'}
                                                        </span>
                                                    </div>
                                                    <div className="flex justify-end">
                                                        <EnumSelect
                                                            name={`item_station_${item.id}`}
                                                            aria-label={`${item.name} station`}
                                                            allLabel="Use category"
                                                            options={
                                                                stationOptions
                                                            }
                                                            value={
                                                                routing.data
                                                                    .items[
                                                                    String(
                                                                        item.id,
                                                                    )
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
                                                                        )]:
                                                                            value,
                                                                    },
                                                                )
                                                            }
                                                            className="w-full sm:w-56"
                                                        />
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}

                                <footer className="flex flex-wrap items-center justify-end gap-2 border-t bg-muted/30 px-4 py-3">
                                    {routing.isDirty && (
                                        <span className="mr-auto text-xs text-muted-foreground">
                                            Unsaved routing changes
                                        </span>
                                    )}
                                    {routing.isDirty && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="max-sm:min-h-11"
                                            disabled={routing.processing}
                                            onClick={() => routing.reset()}
                                        >
                                            Reset
                                        </Button>
                                    )}
                                    <Button
                                        type="submit"
                                        variant={
                                            routing.isDirty
                                                ? 'default'
                                                : 'secondary'
                                        }
                                        className="max-sm:min-h-11"
                                        disabled={
                                            routing.processing ||
                                            !routing.isDirty
                                        }
                                        focusableWhenDisabled
                                    >
                                        {routing.processing && (
                                            <Spinner data-icon="inline-start" />
                                        )}
                                        Save routing
                                    </Button>
                                </footer>
                            </form>
                        </section>
                    )}
                </div>
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
