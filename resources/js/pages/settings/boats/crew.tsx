import { boats as boatsSettings } from '@/routes/settings';
import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Users } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { PhoneInput } from '@/components/pms/phone-input';
import { FormOverlay } from '@/components/pms/form-overlay';
import { PageHeader } from '@/components/pms/page-header';
import {
    BoatSettingsTabs,
    boatSettingsTabs,
} from '@/components/pms/settings/boat-settings-tabs';
import { withTabCrumb } from '@/components/pms/tab-links';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
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
import { store, update } from '@/routes/settings/boats/crew';

type CrewMember = App.Data.Boats.BoatCrewMemberData;

/** The full row, so the update route can save it with a new `active`. */
function crewValues(member: CrewMember) {
    return { name: member.name, phone: member.phone ?? '' };
}

function CrewDialog({
    open,
    onOpenChange,
    member,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    member: CrewMember | null;
}) {
    const form = useForm(
        member === null ? { name: '', phone: '' } : crewValues(member),
    );

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (member === null) {
            form.post(store.url(), options);
        } else {
            form.put(update.url({ crewMember: member.id }), options);
        }
    };

    return (
        <FormOverlay
            variant="dialog"
            size="sm"
            open={open}
            onOpenChange={onOpenChange}
            title={member === null ? 'Add crew' : `Edit ${member.name}`}
            errors={form.errors}
            dirty={form.isDirty}
            onSubmit={submit}
            submitLabel={member === null ? 'Add crew' : 'Save'}
            processing={form.processing}
        >
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="crew-name">Name</FieldLabel>
                <Input
                    id="crew-name"
                    maxLength={60}
                    placeholder="e.g. Awang"
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
                <FieldLabel htmlFor="crew-phone">Phone (optional)</FieldLabel>
                <PhoneInput
                    id="crew-phone"
                    value={form.data.phone}
                    onChange={(value) => form.setData('phone', value)}
                    invalid={!!form.errors.phone}
                />
                <FieldError>{form.errors.phone}</FieldError>
            </Field>
        </FormOverlay>
    );
}

export default function BoatCrewPage({ crew }: { crew: CrewMember[] }) {
    const [dialog, setDialog] = useState<{
        open: boolean;
        member: CrewMember | null;
    }>({ open: false, member: null });
    const [togglingIds, setTogglingIds] = useState<Set<number>>(new Set());

    const toggleActive = (member: CrewMember, active: boolean) => {
        const setToggling = (toggling: boolean) => {
            setTogglingIds((current) => {
                const next = new Set(current);

                if (toggling) {
                    next.add(member.id);
                } else {
                    next.delete(member.id);
                }

                return next;
            });
        };

        router
            .optimistic<{ crew: CrewMember[] }>((props) => ({
                crew: props.crew.map((item) =>
                    item.id === member.id ? { ...item, active } : item,
                ),
            }))
            .put(
                update.url({ crewMember: member.id }),
                { ...crewValues(member), active },
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
                    description="The people who run your boats. Pick who is in charge of each departure."
                />

                <BoatSettingsTabs activeTab="crew">
                    <section className="flex flex-col gap-3">
                        <div className="flex items-center justify-between gap-4">
                            <h2 className="text-base font-medium">Crew</h2>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setDialog({ open: true, member: null })
                                }
                            >
                                <Plus data-icon="inline-start" />
                                Add crew
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
                                        <TableHead className="w-12">
                                            <span className="sr-only">
                                                Edit
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {crew.length === 0 && (
                                        <TableRow className="hover:bg-transparent">
                                            <TableCell colSpan={4}>
                                                <EmptyState
                                                    icon={Users}
                                                    title="No crew yet."
                                                    description="Add the people who run your boats, then pick who is in charge of each trip."
                                                />
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {crew.map((member) => (
                                        <TableRow
                                            key={member.id}
                                            className={
                                                member.active
                                                    ? undefined
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            <TableCell>
                                                <Switch
                                                    size="sm"
                                                    checked={member.active}
                                                    disabled={togglingIds.has(
                                                        member.id,
                                                    )}
                                                    aria-label={`Make ${member.name} ${member.active ? 'unavailable' : 'available'}`}
                                                    onCheckedChange={(active) =>
                                                        toggleActive(
                                                            member,
                                                            active,
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {member.name}
                                            </TableCell>
                                            <TableCell>
                                                {member.phone ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Edit ${member.name}`}
                                                    onClick={() =>
                                                        setDialog({
                                                            open: true,
                                                            member,
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
                </BoatSettingsTabs>
            </div>

            <CrewDialog
                key={`${dialog.member?.id ?? 'new'}-${dialog.open}`}
                open={dialog.open}
                onOpenChange={(open) =>
                    setDialog((current) => ({ ...current, open }))
                }
                member={dialog.member}
            />
        </>
    );
}

BoatCrewPage.layout = {
    breadcrumbs: withTabCrumb(
        [{ title: 'Boats', href: boatsSettings() }],
        boatSettingsTabs(),
        'crew',
    ),
};
