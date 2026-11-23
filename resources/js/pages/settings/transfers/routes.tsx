import { Head, useForm } from '@inertiajs/react';
import { Pencil, Plus, Route as RouteIcon } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { formatMoney } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import {
    TransferSettingsTabs,
    transferSettingsTabs,
} from '@/components/pms/settings/transfer-settings-tabs';
import { useAvailableSwitch } from '@/components/pms/settings/use-available-switch';
import { withTabCrumb } from '@/components/pms/tab-links';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { transfers as transfersSettings } from '@/routes/settings';
import { store, update } from '@/routes/settings/transfers/routes';

type TransferRoute = App.Data.Transfers.TransferRouteData;

/** The Select value for "No charge". */
const NO_CHARGE = '';

/** The full row, so the update route can save it with a new `active`. */
function routeValues(route: TransferRoute) {
    return {
        direction: route.direction as string,
        name: route.name,
        place: route.place,
        minutes: route.minutes === null ? '' : String(route.minutes),
        shared_charge_item_id:
            route.sharedChargeItemId === null
                ? NO_CHARGE
                : String(route.sharedChargeItemId),
        private_charge_item_id:
            route.privateChargeItemId === null
                ? NO_CHARGE
                : String(route.privateChargeItemId),
    };
}

function RouteSheet({
    open,
    onOpenChange,
    route,
    directions,
    chargeItems,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    route: TransferRoute | null;
    directions: EnumOption[];
    chargeItems: EnumOption[];
}) {
    const form = useForm(
        route === null
            ? {
                  direction: 'in',
                  name: '',
                  place: '',
                  minutes: '',
                  shared_charge_item_id: NO_CHARGE,
                  private_charge_item_id: NO_CHARGE,
              }
            : routeValues(route),
    );
    const priceOptions = [
        { value: NO_CHARGE, label: 'No charge' },
        ...chargeItems,
    ];

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (route === null) {
            form.post(store.url(), options);
        } else {
            form.put(update.url({ transferRoute: route.id }), options);
        }
    };

    return (
        <FormOverlay
            variant="sheet"
            open={open}
            onOpenChange={onOpenChange}
            title={route === null ? 'Add route' : `Edit ${route.name}`}
            errors={form.errors}
            dirty={form.isDirty}
            onSubmit={submit}
            submitLabel={route === null ? 'Add route' : 'Save'}
            processing={form.processing}
        >
            <Field data-invalid={!!form.errors.direction}>
                <FieldLabel htmlFor="route-direction">Direction</FieldLabel>
                <EnumSelect
                    id="route-direction"
                    name="direction"
                    options={directions}
                    value={form.data.direction}
                    onValueChange={(value) => form.setData('direction', value)}
                    aria-invalid={!!form.errors.direction}
                />
                <FieldError>{form.errors.direction}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="route-name">Name</FieldLabel>
                <Input
                    id="route-name"
                    maxLength={60}
                    placeholder={
                        form.data.direction === 'in'
                            ? 'e.g. Sandakan Airport to hotel'
                            : 'e.g. Hotel to Sandakan Airport'
                    }
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    aria-invalid={!!form.errors.name}
                />
                <FieldError>{form.errors.name}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.place}>
                <FieldLabel htmlFor="route-place">
                    {form.data.direction === 'in'
                        ? 'Pick-up at'
                        : 'Drop-off at'}
                </FieldLabel>
                <Input
                    id="route-place"
                    maxLength={120}
                    placeholder="e.g. Sandakan Airport arrival hall"
                    value={form.data.place}
                    onChange={(event) =>
                        form.setData('place', event.target.value)
                    }
                    aria-invalid={!!form.errors.place}
                />
                <FieldError>{form.errors.place}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.minutes}>
                <FieldLabel htmlFor="route-minutes">
                    Drive in minutes (optional)
                </FieldLabel>
                <Input
                    id="route-minutes"
                    type="number"
                    inputMode="numeric"
                    min={1}
                    placeholder="e.g. 40"
                    value={form.data.minutes}
                    onChange={(event) =>
                        form.setData('minutes', event.target.value)
                    }
                    aria-invalid={!!form.errors.minutes}
                />
                <FieldError>{form.errors.minutes}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.shared_charge_item_id}>
                <FieldLabel htmlFor="route-shared">Shared price</FieldLabel>
                <EnumSelect
                    id="route-shared"
                    name="shared_charge_item_id"
                    options={priceOptions}
                    value={form.data.shared_charge_item_id}
                    onValueChange={(value) =>
                        form.setData('shared_charge_item_id', value)
                    }
                    aria-invalid={!!form.errors.shared_charge_item_id}
                />
                <FieldDescription>
                    Added when a room takes the shared van. Use a charge item
                    charged per guest.
                </FieldDescription>
                <FieldError>{form.errors.shared_charge_item_id}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.private_charge_item_id}>
                <FieldLabel htmlFor="route-private">Private price</FieldLabel>
                <EnumSelect
                    id="route-private"
                    name="private_charge_item_id"
                    options={priceOptions}
                    value={form.data.private_charge_item_id}
                    onValueChange={(value) =>
                        form.setData('private_charge_item_id', value)
                    }
                    aria-invalid={!!form.errors.private_charge_item_id}
                />
                <FieldDescription>
                    Added once when a room books a private vehicle. A package
                    that includes the item is not charged again.
                </FieldDescription>
                <FieldError>{form.errors.private_charge_item_id}</FieldError>
            </Field>
        </FormOverlay>
    );
}

export default function TransferRoutesPage({
    routes,
    directions,
    chargeItems,
    currency,
}: {
    routes: TransferRoute[];
    directions: EnumOption[];
    chargeItems: EnumOption[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [sheet, setSheet] = useState<{
        open: boolean;
        route: TransferRoute | null;
    }>({ open: false, route: null });
    const { togglingIds, toggle } = useAvailableSwitch<TransferRoute>('routes');
    const price = (amount: string | null, name: string | null) =>
        amount === null ? '—' : `${name} · ${formatMoney(amount, currency)}`;

    return (
        <>
            <Head title="Routes" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Transfers"
                    description="Road routes in and out, with the prices a shared or private transfer adds to the room."
                />

                <TransferSettingsTabs activeTab="routes">
                    <section className="flex flex-col gap-3">
                        <div className="flex items-center justify-between gap-4">
                            <h2 className="text-base font-medium">Routes</h2>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setSheet({ open: true, route: null })
                                }
                            >
                                <Plus data-icon="inline-start" />
                                Add route
                            </Button>
                        </div>

                        <div className="overflow-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-28">
                                            Available
                                        </TableHead>
                                        <TableHead>Direction</TableHead>
                                        <TableHead>Name</TableHead>
                                        <TableHead>
                                            Pick-up or drop-off
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Drive
                                        </TableHead>
                                        <TableHead>Shared price</TableHead>
                                        <TableHead>Private price</TableHead>
                                        <TableHead className="w-12">
                                            <span className="sr-only">
                                                Edit
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {routes.length === 0 && (
                                        <TableRow className="hover:bg-transparent">
                                            <TableCell colSpan={8}>
                                                <EmptyState
                                                    icon={RouteIcon}
                                                    title="No routes yet."
                                                    description="Add a route for each pick-up place, such as the airport to the hotel and back."
                                                />
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {routes.map((route) => (
                                        <TableRow
                                            key={route.id}
                                            className={
                                                route.active
                                                    ? undefined
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            <TableCell>
                                                <Switch
                                                    size="sm"
                                                    checked={route.active}
                                                    disabled={togglingIds.has(
                                                        route.id,
                                                    )}
                                                    aria-label={`Make ${route.name} ${route.active ? 'unavailable' : 'available'}`}
                                                    onCheckedChange={(active) =>
                                                        toggle(
                                                            route,
                                                            active,
                                                            update.url({
                                                                transferRoute:
                                                                    route.id,
                                                            }),
                                                            routeValues(route),
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell>
                                                {route.directionLabel}
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {route.name}
                                            </TableCell>
                                            <TableCell>{route.place}</TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {route.minutes === null
                                                    ? '—'
                                                    : `${route.minutes} min`}
                                            </TableCell>
                                            <TableCell>
                                                {price(
                                                    route.sharedAmount,
                                                    route.sharedChargeItemName,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {price(
                                                    route.privateAmount,
                                                    route.privateChargeItemName,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Edit ${route.name}`}
                                                    onClick={() =>
                                                        setSheet({
                                                            open: true,
                                                            route,
                                                        })
                                                    }
                                                >
                                                    <Pencil />
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </section>
                </TransferSettingsTabs>
            </div>

            <RouteSheet
                key={`${sheet.route?.id ?? 'new'}-${sheet.open}`}
                open={sheet.open}
                onOpenChange={(open) =>
                    setSheet((current) => ({ ...current, open }))
                }
                route={sheet.route}
                directions={directions}
                chargeItems={chargeItems}
            />
        </>
    );
}

TransferRoutesPage.layout = {
    breadcrumbs: withTabCrumb(
        [{ title: 'Transfers', href: transfersSettings() }],
        transferSettingsTabs(),
        'routes',
    ),
};
