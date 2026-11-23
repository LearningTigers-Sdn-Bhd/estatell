import { Head, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import {
    FacilitySheet,
    facilityValues,
} from '@/components/pms/settings/facility-sheet';
import type {
    BookedByOption,
    RateBasesByBookedBy,
} from '@/components/pms/settings/facility-details-form';
import { FacilityTable } from '@/components/pms/settings/facility-table';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import { index, reorder, update } from '@/routes/settings/facilities';

type Facility = App.Data.Facilities.FacilityData;

export default function FacilitiesIndex({
    facilities,
    bookedByOptions,
    rateBases,
    currency,
}: {
    facilities: Facility[];
    bookedByOptions: BookedByOption[];
    rateBases: RateBasesByBookedBy;
    currency: App.Data.Property.CurrencyData | null;
}) {
    const [adding, setAdding] = useState(false);
    const [busyIds, setBusyIds] = useState<Set<number>>(new Set());

    const setBusy = (id: number, busy: boolean) =>
        setBusyIds((current) => {
            const next = new Set(current);

            if (busy) {
                next.add(id);
            } else {
                next.delete(id);
            }

            return next;
        });

    const move = (from: number, to: number) => {
        const order = facilities.map((facility) => facility.id);
        order.splice(to, 0, ...order.splice(from, 1));

        router
            .optimistic<{ facilities: Facility[] }>((props) => ({
                facilities: order
                    .map((id) =>
                        props.facilities.find((item) => item.id === id),
                    )
                    .filter((item): item is Facility => item !== undefined),
            }))
            .put(reorder.url(), { order }, { preserveScroll: true });
    };

    const toggleActive = (facility: Facility, active: boolean) => {
        router
            .optimistic<{ facilities: Facility[] }>((props) => ({
                facilities: props.facilities.map((item) =>
                    item.id === facility.id ? { ...item, active } : item,
                ),
            }))
            .put(
                update.url({ facility: facility.id }),
                { ...facilityValues(facility), active },
                {
                    preserveScroll: true,
                    onStart: () => setBusy(facility.id, true),
                    onFinish: () => setBusy(facility.id, false),
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
            <Head title="Facilities" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Facilities"
                    description="Halls, equipment and activities guests can book."
                    actions={
                        <Button onClick={() => setAdding(true)}>
                            <Plus data-icon="inline-start" />
                            Add facility
                        </Button>
                    }
                />

                <FacilityTable
                    facilities={facilities}
                    currency={currency}
                    busyIds={busyIds}
                    onMove={move}
                    onToggleActive={toggleActive}
                />
            </div>

            <FacilitySheet
                open={adding}
                onOpenChange={setAdding}
                bookedByOptions={bookedByOptions}
                rateBases={rateBases}
                currency={currency}
            />
        </>
    );
}

FacilitiesIndex.layout = {
    breadcrumbs: [{ title: 'Facilities', href: index() }],
};
