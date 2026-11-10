import { boats as boatsSettings } from '@/routes/settings';
import { Head, router, useForm } from '@inertiajs/react';
import { Anchor, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { FormOverlay } from '@/components/pms/form-overlay';
import { PageHeader } from '@/components/pms/page-header';
import { BoatSettingsTabs } from '@/components/pms/settings/boat-settings-tabs';
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
import { toast } from '@/components/ui/toast';
import { store, update } from '@/routes/settings/boats/boats';

type Boat = App.Data.Boats.BoatData;

/** The full row, so the update route can save it with a new `active`. */
function boatValues(boat: Boat) {
    return {
        name: boat.name,
        registration: boat.registration ?? '',
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
        boat === null
            ? { name: '', registration: '', seats: '' }
            : boatValues(boat),
    );

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (boat === null) {
            form.post(store.url(), options);
        } else {
            form.put(update.url({ boat: boat.id }), options);
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
                    placeholder="e.g. MV Sand Bay 1"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    aria-invalid={!!form.errors.name}
                    autoFocus
                />
                <FieldDescription>
                    Printed on the boat manifest.
                </FieldDescription>
                <FieldError>{form.errors.name}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.registration}>
                <FieldLabel htmlFor="boat-registration">
                    Registration (optional)
                </FieldLabel>
                <Input
                    id="boat-registration"
                    maxLength={30}
                    placeholder="e.g. SA10716"
                    value={form.data.registration}
                    onChange={(event) =>
                        form.setData('registration', event.target.value)
                    }
                    aria-invalid={!!form.errors.registration}
                />
                <FieldError>{form.errors.registration}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.seats}>
                <FieldLabel htmlFor="boat-seats">Seats (optional)</FieldLabel>
                <Input
                    id="boat-seats"
                    type="number"
                    inputMode="numeric"
                    min={1}
                    placeholder="e.g. 20"
                    value={form.data.seats}
                    onChange={(event) =>
                        form.setData('seats', event.target.value)
                    }
                    aria-invalid={!!form.errors.seats}
                />
                <FieldDescription>Leave empty for no limit.</FieldDescription>
                <FieldError>{form.errors.seats}</FieldError>
            </Field>
        </FormOverlay>
    );
}

export default function BoatsPage({ boats }: { boats: Boat[] }) {
    const [dialog, setDialog] = useState<{ open: boolean; boat: Boat | null }>({
        open: false,
        boat: null,
    });
    const [togglingIds, setTogglingIds] = useState<Set<number>>(new Set());

    const toggleActive = (boat: Boat, active: boolean) => {
        const setToggling = (toggling: boolean) => {
            setTogglingIds((current) => {
                const next = new Set(current);

                if (toggling) {
                    next.add(boat.id);
                } else {
                    next.delete(boat.id);
                }

                return next;
            });
        };

        router
            .optimistic<{ boats: Boat[] }>((props) => ({
                boats: props.boats.map((item) =>
                    item.id === boat.id ? { ...item, active } : item,
                ),
            }))
            .put(
                update.url({ boat: boat.id }),
                { ...boatValues(boat), active },
                {
                    preserveScroll: true,
                    onStart: () => setToggling(true),
                    onFinish: () => setToggling(false),
                    onError: (errors) =>
                        toast.add({
                            title:
                                Object.values(errors)[0] ??
                                'The change could not be saved.',
                            type: 'error',
                        }),
                },
            );
    };

    return (
        <>
            <Head title="Boats" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Boats"
                    description="The boats you run, picked for each departure on the boat manifest."
                />

                <BoatSettingsTabs activeTab="boats">
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

                        {boats.length === 0 ? (
                            <EmptyState
                                icon={Anchor}
                                title="No boats yet."
                                description="Add the boats you run, so the Boats board can name the boat of each sailing."
                            />
                        ) : (
                            <div className="overflow-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-28">
                                                Available
                                            </TableHead>
                                            <TableHead>Name</TableHead>
                                            <TableHead>Registration</TableHead>
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
                                                        onCheckedChange={(
                                                            active,
                                                        ) =>
                                                            toggleActive(
                                                                boat,
                                                                active,
                                                            )
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {boat.name}
                                                </TableCell>
                                                <TableCell>
                                                    {boat.registration ?? '—'}
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
                        )}
                    </section>
                </BoatSettingsTabs>
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

BoatsPage.layout = {
    breadcrumbs: [{ title: 'Boats', href: boatsSettings() }],
};
