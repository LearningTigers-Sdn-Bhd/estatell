import { Head, useForm } from '@inertiajs/react';
import { Pencil, Plus, UserRound } from 'lucide-react';
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
import { store, update } from '@/routes/settings/transfers/drivers';

type Driver = App.Data.Transfers.TransferDriverData;

/** The full row, so the update route can save it with a new `active`. */
function driverValues(driver: Driver) {
    return {
        name: driver.name,
        phone: driver.phone ?? '',
        subcontractor: driver.subcontractor ?? '',
    };
}

function DriverDialog({
    open,
    onOpenChange,
    driver,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    driver: Driver | null;
}) {
    const form = useForm(
        driver === null
            ? { name: '', phone: '', subcontractor: '' }
            : driverValues(driver),
    );

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (driver === null) {
            form.post(store.url(), options);
        } else {
            form.put(update.url({ transferDriver: driver.id }), options);
        }
    };

    return (
        <FormOverlay
            variant="dialog"
            size="sm"
            open={open}
            onOpenChange={onOpenChange}
            title={driver === null ? 'Add driver' : `Edit ${driver.name}`}
            errors={form.errors}
            dirty={form.isDirty}
            onSubmit={submit}
            submitLabel={driver === null ? 'Add driver' : 'Save'}
            processing={form.processing}
        >
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="driver-name">Name</FieldLabel>
                <Input
                    id="driver-name"
                    maxLength={60}
                    placeholder="e.g. Ali"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    aria-invalid={!!form.errors.name}
                    autoFocus
                />
                <FieldError>{form.errors.name}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.phone}>
                <FieldLabel htmlFor="driver-phone">Phone (optional)</FieldLabel>
                <Input
                    id="driver-phone"
                    type="tel"
                    maxLength={30}
                    placeholder="e.g. +60 12 345 6789"
                    value={form.data.phone}
                    onChange={(event) =>
                        form.setData('phone', event.target.value)
                    }
                    aria-invalid={!!form.errors.phone}
                />
                <FieldError>{form.errors.phone}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.subcontractor}>
                <FieldLabel htmlFor="driver-subcontractor">
                    Subcontractor (optional)
                </FieldLabel>
                <Input
                    id="driver-subcontractor"
                    maxLength={60}
                    placeholder="e.g. Kinabatangan Tours"
                    value={form.data.subcontractor}
                    onChange={(event) =>
                        form.setData('subcontractor', event.target.value)
                    }
                    aria-invalid={!!form.errors.subcontractor}
                />
                <FieldDescription>
                    The company this driver works for. Leave empty for your own
                    staff.
                </FieldDescription>
                <FieldError>{form.errors.subcontractor}</FieldError>
            </Field>
        </FormOverlay>
    );
}

export default function TransferDriversPage({
    drivers,
}: {
    drivers: Driver[];
}) {
    const [dialog, setDialog] = useState<{
        open: boolean;
        driver: Driver | null;
    }>({ open: false, driver: null });
    const { togglingIds, toggle } = useAvailableSwitch<Driver>('drivers');

    return (
        <>
            <Head title="Drivers" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Transfers"
                    description="Your own vans and cars, picked for each transfer on the run sheet. A subcontractor's driver is not listed."
                />

                <TransferSettingsTabs activeTab="drivers">
                    <section className="flex flex-col gap-3">
                        <div className="flex items-center justify-between gap-4">
                            <h2 className="text-base font-medium">Drivers</h2>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setDialog({ open: true, driver: null })
                                }
                            >
                                <Plus data-icon="inline-start" />
                                Add driver
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
                                        <TableHead>Phone</TableHead>
                                        <TableHead>Works for</TableHead>
                                        <TableHead className="w-12">
                                            <span className="sr-only">
                                                Edit
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {drivers.length === 0 && (
                                        <TableRow className="hover:bg-transparent">
                                            <TableCell colSpan={5}>
                                                <EmptyState
                                                    icon={UserRound}
                                                    title="No drivers yet."
                                                    description="Add the drivers who pick guests up, so the run sheet can name who drove."
                                                />
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {drivers.map((driver) => (
                                        <TableRow
                                            key={driver.id}
                                            className={
                                                driver.active
                                                    ? undefined
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            <TableCell>
                                                <Switch
                                                    size="sm"
                                                    checked={driver.active}
                                                    disabled={togglingIds.has(
                                                        driver.id,
                                                    )}
                                                    aria-label={`Make ${driver.name} ${driver.active ? 'unavailable' : 'available'}`}
                                                    onCheckedChange={(active) =>
                                                        toggle(
                                                            driver,
                                                            active,
                                                            update.url({
                                                                transferDriver:
                                                                    driver.id,
                                                            }),
                                                            driverValues(
                                                                driver,
                                                            ),
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {driver.name}
                                            </TableCell>
                                            <TableCell>
                                                {driver.phone ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                {driver.subcontractor ??
                                                    'Own staff'}
                                            </TableCell>
                                            <TableCell>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Edit ${driver.name}`}
                                                    onClick={() =>
                                                        setDialog({
                                                            open: true,
                                                            driver,
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

            <DriverDialog
                key={`${dialog.driver?.id ?? 'new'}-${dialog.open}`}
                open={dialog.open}
                onOpenChange={(open) =>
                    setDialog((current) => ({ ...current, open }))
                }
                driver={dialog.driver}
            />
        </>
    );
}

TransferDriversPage.layout = {
    breadcrumbs: withTabCrumb(
        [{ title: 'Transfers', href: transfersSettings() }],
        transferSettingsTabs(),
        'drivers',
    ),
};
