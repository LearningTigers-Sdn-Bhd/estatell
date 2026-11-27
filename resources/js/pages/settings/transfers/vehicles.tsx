import { Head, useForm } from '@inertiajs/react';
import { Bus, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { FormOverlay } from '@/components/pms/form-overlay';
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
import { store, update } from '@/routes/settings/transfers/vehicles';

type Vehicle = App.Data.Transfers.TransferVehicleData;

/** The full row, so the update route can save it with a new `active`. */
function vehicleValues(vehicle: Vehicle) {
    return {
        name: vehicle.name,
        plate: vehicle.plate ?? '',
        seats: vehicle.seats === null ? '' : String(vehicle.seats),
    };
}

function VehicleDialog({
    open,
    onOpenChange,
    vehicle,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    vehicle: Vehicle | null;
}) {
    const form = useForm(
        vehicle === null
            ? { name: '', plate: '', seats: '' }
            : vehicleValues(vehicle),
    );

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (vehicle === null) {
            form.post(store.url(), options);
        } else {
            form.put(update.url({ transferVehicle: vehicle.id }), options);
        }
    };

    return (
        <FormOverlay
            variant="dialog"
            size="sm"
            open={open}
            onOpenChange={onOpenChange}
            title={vehicle === null ? 'Add vehicle' : `Edit ${vehicle.name}`}
            errors={form.errors}
            dirty={form.isDirty}
            onSubmit={submit}
            submitLabel={vehicle === null ? 'Add vehicle' : 'Save'}
            processing={form.processing}
        >
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="vehicle-name">Name</FieldLabel>
                <Input
                    id="vehicle-name"
                    maxLength={60}
                    placeholder="e.g. Van 1"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    aria-invalid={!!form.errors.name}
                    autoFocus
                />
                <FieldError>{form.errors.name}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.plate}>
                <FieldLabel htmlFor="vehicle-plate">
                    Plate (optional)
                </FieldLabel>
                <Input
                    id="vehicle-plate"
                    maxLength={20}
                    placeholder="e.g. SA 1234 B"
                    value={form.data.plate}
                    onChange={(event) =>
                        form.setData('plate', event.target.value)
                    }
                    aria-invalid={!!form.errors.plate}
                />
                <FieldError>{form.errors.plate}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.seats}>
                <FieldLabel htmlFor="vehicle-seats">
                    Seats (optional)
                </FieldLabel>
                <Input
                    id="vehicle-seats"
                    type="number"
                    inputMode="numeric"
                    min={1}
                    placeholder="e.g. 10"
                    value={form.data.seats}
                    onChange={(event) =>
                        form.setData('seats', event.target.value)
                    }
                    aria-invalid={!!form.errors.seats}
                />
                <FieldDescription>
                    Guest seats, not the driver.
                </FieldDescription>
                <FieldError>{form.errors.seats}</FieldError>
            </Field>
        </FormOverlay>
    );
}

export default function TransferVehiclesPage({
    vehicles,
}: {
    vehicles: Vehicle[];
}) {
    const [dialog, setDialog] = useState<{
        open: boolean;
        vehicle: Vehicle | null;
    }>({ open: false, vehicle: null });
    const { togglingIds, toggle } = useAvailableSwitch<Vehicle>('vehicles');

    return (
        <>
            <Head title="Vehicles" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Transfers"
                    description="Your own vans and cars, picked for each transfer on the run sheet. A subcontractor's vehicle is not listed."
                />

                <TransferSettingsTabs activeTab="vehicles">
                    <section className="flex flex-col gap-3">
                        <div className="flex items-center justify-between gap-4">
                            <h2 className="text-base font-medium">Vehicles</h2>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setDialog({ open: true, vehicle: null })
                                }
                            >
                                <Plus data-icon="inline-start" />
                                Add vehicle
                            </Button>
                        </div>

                        <div className="overflow-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-28">
                                            Available
                                        </TableHead>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Plate</TableHead>
                                        <TableHead className="text-right">
                                            Seats
                                        </TableHead>
                                        <TableHead className="w-12">
                                            <span className="sr-only">
                                                Edit
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {vehicles.length === 0 && (
                                        <TableRow className="hover:bg-transparent">
                                            <TableCell colSpan={5}>
                                                <EmptyState
                                                    icon={Bus}
                                                    title="No vehicles yet."
                                                    description="Add your vans and cars, so the run sheet can name the vehicle of each transfer."
                                                />
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {vehicles.map((vehicle) => (
                                        <TableRow
                                            key={vehicle.id}
                                            className={
                                                vehicle.active
                                                    ? undefined
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            <TableCell>
                                                <Switch
                                                    size="sm"
                                                    checked={vehicle.active}
                                                    disabled={togglingIds.has(
                                                        vehicle.id,
                                                    )}
                                                    aria-label={`Make ${vehicle.name} ${vehicle.active ? 'unavailable' : 'available'}`}
                                                    onCheckedChange={(active) =>
                                                        toggle(
                                                            vehicle,
                                                            active,
                                                            update.url({
                                                                transferVehicle:
                                                                    vehicle.id,
                                                            }),
                                                            vehicleValues(
                                                                vehicle,
                                                            ),
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {vehicle.name}
                                            </TableCell>
                                            <TableCell>
                                                {vehicle.plate ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {vehicle.seats ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Edit ${vehicle.name}`}
                                                    onClick={() =>
                                                        setDialog({
                                                            open: true,
                                                            vehicle,
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

            <VehicleDialog
                key={`${dialog.vehicle?.id ?? 'new'}-${dialog.open}`}
                open={dialog.open}
                onOpenChange={(open) =>
                    setDialog((current) => ({ ...current, open }))
                }
                vehicle={dialog.vehicle}
            />
        </>
    );
}

TransferVehiclesPage.layout = {
    breadcrumbs: withTabCrumb(
        [{ title: 'Transfers', href: transfersSettings() }],
        transferSettingsTabs(),
        'vehicles',
    ),
};
