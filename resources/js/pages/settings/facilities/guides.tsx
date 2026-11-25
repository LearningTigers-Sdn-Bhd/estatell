import { Head, useForm } from '@inertiajs/react';
import { Pencil, Plus, UserRound } from 'lucide-react';
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
import { index as facilitiesIndex } from '@/routes/settings/facilities';
import { store, update } from '@/routes/settings/facilities/guides';

type Guide = App.Data.Facilities.FacilityGuideData;

/** The full row, so the update route can save it with a new `active`. */
function guideValues(guide: Guide) {
    return {
        name: guide.name,
        phone: guide.phone ?? '',
    };
}

function GuideDialog({
    open,
    onOpenChange,
    guide,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    guide: Guide | null;
}) {
    const form = useForm(
        guide === null ? { name: '', phone: '' } : guideValues(guide),
    );

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (guide === null) {
            form.post(store.url(), options);
        } else {
            form.put(update.url({ facilityGuide: guide.id }), options);
        }
    };

    return (
        <FormOverlay
            variant="dialog"
            size="sm"
            open={open}
            onOpenChange={onOpenChange}
            title={guide === null ? 'Add guide' : `Edit ${guide.name}`}
            errors={form.errors}
            dirty={form.isDirty}
            onSubmit={submit}
            submitLabel={guide === null ? 'Add guide' : 'Save'}
            processing={form.processing}
        >
            <Field data-invalid={!!form.errors.name}>
                <FieldLabel htmlFor="guide-name">Name</FieldLabel>
                <Input
                    id="guide-name"
                    maxLength={60}
                    placeholder="e.g. Jamil"
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
                <FieldLabel htmlFor="guide-phone">Phone (optional)</FieldLabel>
                <Input
                    id="guide-phone"
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
        </FormOverlay>
    );
}

export default function FacilityGuidesPage({ guides }: { guides: Guide[] }) {
    const [dialog, setDialog] = useState<{
        open: boolean;
        guide: Guide | null;
    }>({ open: false, guide: null });
    const { togglingIds, toggle } = useAvailableSwitch<Guide>('guides');

    return (
        <>
            <Head title="Guides" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Facilities"
                    description="Halls, equipment and activities guests can book, and the guides and boats of the activities."
                />

                <FacilitySettingsTabs activeTab="guides">
                    <section className="flex flex-col gap-3">
                        <div className="flex items-center justify-between gap-4">
                            <h2 className="text-base font-medium">Guides</h2>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setDialog({ open: true, guide: null })
                                }
                            >
                                <Plus data-icon="inline-start" />
                                Add guide
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
                                    {guides.length === 0 && (
                                        <TableRow className="hover:bg-transparent">
                                            <TableCell colSpan={4}>
                                                <EmptyState
                                                    icon={UserRound}
                                                    title="No guides yet."
                                                    description="Add the guides who lead the activities, so the Activities run sheet can name who leads each session."
                                                />
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {guides.map((guide) => (
                                        <TableRow
                                            key={guide.id}
                                            className={
                                                guide.active
                                                    ? undefined
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            <TableCell>
                                                <Switch
                                                    size="sm"
                                                    checked={guide.active}
                                                    disabled={togglingIds.has(
                                                        guide.id,
                                                    )}
                                                    aria-label={`Make ${guide.name} ${guide.active ? 'unavailable' : 'available'}`}
                                                    onCheckedChange={(active) =>
                                                        toggle(
                                                            guide,
                                                            active,
                                                            update.url({
                                                                facilityGuide:
                                                                    guide.id,
                                                            }),
                                                            guideValues(guide),
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {guide.name}
                                            </TableCell>
                                            <TableCell>
                                                {guide.phone ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Edit ${guide.name}`}
                                                    onClick={() =>
                                                        setDialog({
                                                            open: true,
                                                            guide,
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

            <GuideDialog
                key={`${dialog.guide?.id ?? 'new'}-${dialog.open}`}
                open={dialog.open}
                onOpenChange={(open) =>
                    setDialog((current) => ({ ...current, open }))
                }
                guide={dialog.guide}
            />
        </>
    );
}

FacilityGuidesPage.layout = {
    breadcrumbs: withTabCrumb(
        [{ title: 'Facilities', href: facilitiesIndex() }],
        facilitySettingsTabs(),
        'guides',
    ),
};
