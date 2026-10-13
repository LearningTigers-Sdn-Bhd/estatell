import { boats } from '@/routes/settings';
import { Head, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { BoatFareSheet } from '@/components/pms/settings/boat-fare-sheet';
import { BoatFareTable } from '@/components/pms/settings/boat-fare-table';
import { BoatSettingsTabs } from '@/components/pms/settings/boat-settings-tabs';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import { update } from '@/routes/settings/boats/fares';

type Fare = App.Data.Boats.BoatFareRowData;
type AgeGroup = App.Data.Boats.BoatFareAgeGroupData;
type Tax = App.Data.Catalogues.ChargeItemTaxData;
type Option = { value: string; label: string };

type SheetState = {
    open: boolean;
    fare: Fare | null;
};

/** The values the Available switch sends. The code, description and child prices stay as they are on the server. */
function fareValues(fare: Fare) {
    return {
        name: fare.name,
        default_amount: fare.amount ?? '',
        allow_override: fare.allowOverride,
        tax_ids: fare.taxes.map((tax) => String(tax.id)),
        active: fare.active,
    };
}

export default function BoatFaresPage({
    fares,
    ageGroups,
    taxes,
    pricings,
    chargingUnits,
    currency,
}: {
    fares: Fare[];
    ageGroups: AgeGroup[];
    taxes: Tax[];
    pricings: Option[];
    chargingUnits: Option[];
    currency: App.Enums.Currency;
}) {
    const [sheet, setSheet] = useState<SheetState>({ open: false, fare: null });
    const [togglingIds, setTogglingIds] = useState<Set<number>>(new Set());
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    });

    const openAdd = () => setSheet({ open: true, fare: null });
    const openEdit = (fare: Fare) => setSheet({ open: true, fare });

    const toggleActive = (fare: Fare, active: boolean) => {
        const setToggling = (toggling: boolean) => {
            setTogglingIds((current) => {
                const next = new Set(current);

                if (toggling) {
                    next.add(fare.id);
                } else {
                    next.delete(fare.id);
                }

                return next;
            });
        };

        router
            .optimistic<{ fares: Fare[] }>((props) => ({
                fares: props.fares.map((item) =>
                    item.id === fare.id ? { ...item, active } : item,
                ),
            }))
            .put(
                update.url({ chargeItem: fare.id }),
                { ...fareValues(fare), active },
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
                    description="The one-way price per guest for each boat. Guests are charged a fare on their booking when they take a boat."
                />

                <BoatSettingsTabs activeTab="fares">
                    <section className="flex flex-col gap-3">
                        <div className="flex items-center justify-between gap-4">
                            <h2 className="text-base font-medium">Fares</h2>
                            <Button variant="outline" onClick={openAdd}>
                                <Plus data-icon="inline-start" />
                                Add fare
                            </Button>
                        </div>

                        <BoatFareTable
                            fares={fares}
                            money={money}
                            currency={currency}
                            chargingUnits={chargingUnits}
                            togglingIds={togglingIds}
                            onToggleActive={toggleActive}
                            onEdit={openEdit}
                        />
                    </section>
                </BoatSettingsTabs>
            </div>

            <BoatFareSheet
                open={sheet.open}
                onOpenChange={(open) =>
                    setSheet((current) => ({ ...current, open }))
                }
                fare={sheet.fare}
                ageGroups={ageGroups}
                taxes={taxes}
                pricings={pricings}
                currency={currency}
            />
        </>
    );
}

BoatFaresPage.layout = {
    breadcrumbs: [{ title: 'Boats', href: boats() }],
};
