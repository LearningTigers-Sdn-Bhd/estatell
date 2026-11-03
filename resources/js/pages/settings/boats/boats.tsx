import { boats as boatsSettings } from '@/routes/settings';
import { Head, useForm } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { PageHeader } from '@/components/pms/page-header';
import { BoatSettingsTabs } from '@/components/pms/settings/boat-settings-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { charter } from '@/routes/settings/boats';
import { store, update } from '@/routes/settings/boats/boats';

type Boat = App.Data.Boats.BoatData;
type Option = { value: string; label: string };

function BoatDialog({
    open,
    onOpenChange,
    boat,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    boat: Boat | null;
}) {
    const form = useForm({
        name: boat?.name ?? '',
        registration: boat?.registration ?? '',
        active: boat?.active ?? true,
    });

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
                    placeholder="SA10716"
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
                    value={form.data.registration}
                    onChange={(event) =>
                        form.setData('registration', event.target.value)
                    }
                    aria-invalid={!!form.errors.registration}
                />
                <FieldError>{form.errors.registration}</FieldError>
            </Field>
            <Field orientation="horizontal">
                <Switch
                    id="boat-active"
                    checked={form.data.active}
                    onCheckedChange={(checked) =>
                        form.setData('active', checked)
                    }
                />
                <FieldContent>
                    <FieldLabel htmlFor="boat-active">In use</FieldLabel>
                    <FieldDescription>
                        Only boats in use can be picked on the manifest.
                    </FieldDescription>
                </FieldContent>
            </Field>
        </FormOverlay>
    );
}

function CharterForm({
    chargeItemId,
    chargeItems,
}: {
    chargeItemId: number | null;
    chargeItems: Option[];
}) {
    const form = useForm({
        charge_item_id: chargeItemId === null ? '' : String(chargeItemId),
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            charge_item_id:
                data.charge_item_id === '' ? null : Number(data.charge_item_id),
        }));
        form.put(charter.url(), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3">
            <h2 className="text-base font-medium">Charter boat</h2>
            <Field data-invalid={!!form.errors.charge_item_id}>
                <FieldLabel htmlFor="charter-charge">
                    Charge for a charter
                </FieldLabel>
                <div className="flex flex-wrap items-center gap-2">
                    <EnumSelect
                        id="charter-charge"
                        className="w-64"
                        options={chargeItems}
                        allLabel="Nothing (charge by hand)"
                        value={form.data.charge_item_id}
                        onValueChange={(value) =>
                            form.setData('charge_item_id', value)
                        }
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={!form.isDirty || form.processing}
                        focusableWhenDisabled
                    >
                        {form.processing && (
                            <Spinner data-icon="inline-start" />
                        )}
                        Save
                    </Button>
                </div>
                <FieldDescription>
                    A Charter trip adds this item once to the room, for example
                    Charter Boat. Changing or clearing the trip removes it while
                    it is not charged.
                </FieldDescription>
                <FieldError>{form.errors.charge_item_id}</FieldError>
            </Field>
        </form>
    );
}

export default function BoatsPage({
    boats,
    charterChargeItemId,
    chargeItems,
}: {
    boats: Boat[];
    charterChargeItemId: number | null;
    chargeItems: Option[];
}) {
    const [dialog, setDialog] = useState<{ open: boolean; boat: Boat | null }>({
        open: false,
        boat: null,
    });

    return (
        <>
            <Head title="Boats" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Boats"
                    description="The boats you pick for each departure on the boat manifest, and what a charter costs."
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
                            <p className="text-sm text-muted-foreground">
                                No boats yet. Add the boats you run, so the
                                manifest can name the boat of each departure.
                            </p>
                        ) : (
                            <div className="overflow-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Name</TableHead>
                                            <TableHead>Registration</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead className="w-12">
                                                <span className="sr-only">
                                                    Edit
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {boats.map((boat) => (
                                            <TableRow key={boat.id}>
                                                <TableCell className="font-medium">
                                                    {boat.name}
                                                </TableCell>
                                                <TableCell>
                                                    {boat.registration ?? '—'}
                                                </TableCell>
                                                <TableCell>
                                                    {boat.active ? (
                                                        <Badge variant="outline">
                                                            In use
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="secondary">
                                                            Not in use
                                                        </Badge>
                                                    )}
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

                    <CharterForm
                        chargeItemId={charterChargeItemId}
                        chargeItems={chargeItems}
                    />
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
