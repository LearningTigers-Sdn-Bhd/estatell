import { boats } from '@/routes/settings';
import { Head, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { BoatFareSheet } from '@/components/pms/settings/boat-fare-sheet';
import { BoatFareTable } from '@/components/pms/settings/boat-fare-table';
import { BoatSettingsTabs } from '@/components/pms/settings/boat-settings-tabs';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { toast } from '@/components/ui/toast';
import { charter } from '@/routes/settings/boats';
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
            <h2 className="text-base font-medium">Charter</h2>
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
                        placeholder="Choose a charge"
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
                    Picking Charter as a room's boat adds this charge once.
                    Changing or clearing the trip removes it while it is not
                    charged.
                </FieldDescription>
                <FieldError>{form.errors.charge_item_id}</FieldError>
            </Field>
        </form>
    );
}

export default function BoatFaresPage({
    fares,
    ageGroups,
    taxes,
    pricings,
    chargingUnits,
    charterChargeItemId,
    chargeItems,
    currency,
}: {
    fares: Fare[];
    ageGroups: AgeGroup[];
    taxes: Tax[];
    pricings: Option[];
    chargingUnits: Option[];
    charterChargeItemId: number | null;
    chargeItems: Option[];
    currency: App.Data.Property.CurrencyData;
}) {
    const [sheet, setSheet] = useState<SheetState>({ open: false, fare: null });
    const [togglingIds, setTogglingIds] = useState<Set<number>>(new Set());
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.code,
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
                    <div className="flex flex-col gap-8">
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

                        <CharterForm
                            chargeItemId={charterChargeItemId}
                            chargeItems={chargeItems}
                        />
                    </div>
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
