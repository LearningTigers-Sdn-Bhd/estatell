import { Head, useForm } from '@inertiajs/react';
import { Pencil, Plus, Sailboat } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { FormOverlay } from '@/components/pms/form-overlay';
import { PageHeader } from '@/components/pms/page-header';
import {
    FacilitySettingsTabs,
    facilitySettingsTabs,
} from '@/components/pms/settings/facility-settings-tabs';
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
import { index as facilitiesIndex } from '@/routes/settings/facilities';
import { store, update } from '@/routes/settings/facilities/boats';

type Boat = App.Data.Facilities.FacilityBoatData;

/** The full row, so the update route can save it with a new `active`. */
function boatValues(boat: Boat) {
    return {
        name: boat.name,
        seats: boat.seats === null ? '' : String(boat.seats),
    };
}

function BoatDialog({
    open,
    onOpenChange,
    boat,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    boat: Boat | null;
}) {
    const form = useForm(
        boat === null ? { name: '', seats: '' } : boatValues(boat),
    );

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (boat === null) {
            form.post(store.url(), options);
        } else {
            form.put(update.url({ facilityBoat: boat.id }), options);
        }
    };

    return (
        <FormOverlay
            variant="dialog"
            size="sm"
            open={open}
            onOpenChange={onOpenChange}
            title={boat === null ? 'Add boat' : `Edit ${boat.name}`}
            errors={form.errors}
            dirty={form.isDirty}
            onSubmit={submit}
            submitLabel={boat === null ? 'Add boat' : 'Save'}
            processing={form.processing}
        >
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="boat-name">Name</FieldLabel>
                <Input
                    id="boat-name"
                    maxLength={60}
                    placeholder="e.g. Kingfisher"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    aria-invalid={!!form.errors.name}
                    autoFocus
                />
                <FieldError>{form.errors.name}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.seats}>
                <FieldLabel htmlFor="boat-seats">Seats (optional)</FieldLabel>
                <Input
                    id="boat-seats"
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
                    Guest seats, not the boatman or guide. The run sheet warns
                    when more people are booked.
                </FieldDescription>
                <FieldError>{form.errors.seats}</FieldError>
            </Field>
        </FormOverlay>
    );
}

export default function FacilityBoatsPage({ boats }: { boats: Boat[] }) {
    const [dialog, setDialog] = useState<{
        open: boolean;
        boat: Boat | null;
    }>({ open: false, boat: null });
    const { togglingIds, toggle } = useAvailableSwitch<Boat>('boats');

    return (
        <>
            <Head title="Boats" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Facilities"
                    description="Halls, equipment and activities guests can book, and the guides and boats of the activities."
                />

                <FacilitySettingsTabs activeTab="boats">
                    <section className="flex flex-col gap-3">
                        <div className="flex items-center justify-between gap-4">
                            <h2 className="text-base font-medium">Boats</h2>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setDialog({ open: true, boat: null })
                                }
                            >
                                <Plus data-icon="inline-start" />
                                Add boat
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
                                    {boats.length === 0 && (
                                        <TableRow className="hover:bg-transparent">
                                            <TableCell colSpan={4}>
                                                <EmptyState
                                                    icon={Sailboat}
                                                    title="No boats yet."
                                                    description="Add the boats that take guests on river safaris and other activities, so the Activities run sheet can name the boat of each session."
                                                />
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {boats.map((boat) => (
                                        <TableRow
                                            key={boat.id}
                                            className={
                                                boat.active
                                                    ? undefined
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            <TableCell>
                                                <Switch
                                                    size="sm"
                                                    checked={boat.active}
                                                    disabled={togglingIds.has(
                                                        boat.id,
                                                    )}
                                                    aria-label={`Make ${boat.name} ${boat.active ? 'unavailable' : 'available'}`}
                                                    onCheckedChange={(active) =>
                                                        toggle(
                                                            boat,
                                                            active,
                                                            update.url({
                                                                facilityBoat:
                                                                    boat.id,
                                                            }),
                                                            boatValues(boat),
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {boat.name}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {boat.seats ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Edit ${boat.name}`}
                                                    onClick={() =>
                                                        setDialog({
                                                            open: true,
                                                            boat,
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
                </FacilitySettingsTabs>
            </div>

            <BoatDialog
                key={`${dialog.boat?.id ?? 'new'}-${dialog.open}`}
                open={dialog.open}
                onOpenChange={(open) =>
                    setDialog((current) => ({ ...current, open }))
                }
                boat={dialog.boat}
            />
        </>
    );
}

FacilityBoatsPage.layout = {
    breadcrumbs: withTabCrumb(
        [{ title: 'Facilities', href: facilitiesIndex() }],
        facilitySettingsTabs(),
        'boats',
    ),
};
