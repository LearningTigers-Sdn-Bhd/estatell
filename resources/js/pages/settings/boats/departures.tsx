import { boats } from '@/routes/settings';
import { Form, Head, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { BoatDepartureSheet } from '@/components/pms/settings/boat-departure-sheet';
import { BoatDepartureTable } from '@/components/pms/settings/boat-departure-table';
import { BoatSettingsTabs } from '@/components/pms/settings/boat-settings-tabs';
import { PageHeader } from '@/components/pms/page-header';
import { Button } from '@/components/ui/button';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Spinner } from '@/components/ui/spinner';
import { toast } from '@/components/ui/toast';
import { destroy, update } from '@/routes/settings/boats/departures';

type Departure = App.Data.Boats.BoatDepartureData;
type Fare = App.Data.Boats.BoatFareData;
type Option = { value: string; label: string };

type SheetState = {
    open: boolean;
    departure: Departure | null;
    direction: string;
};

/** The full row, so the update route can save it with a new `active`. */
function formValues(departure: Departure) {
    return {
        direction: departure.direction,
        leaves_at: departure.leavesAt,
        arrives_at: departure.arrivesAt,
        pickup_point: departure.pickupPoint ?? '',
        seats: departure.seats === null ? '' : String(departure.seats),
        charge_item_id:
            departure.chargeItemId === null
                ? ''
                : String(departure.chargeItemId),
        active: departure.active,
    };
}

export default function BoatDeparturesPage({
    departures,
    mealPeriods,
    fares,
    directions,
    currency,
}: {
    departures: Departure[];
    mealPeriods: App.Data.Meals.MealPeriodData[];
    fares: Fare[];
    directions: Option[];
    currency: App.Data.Property.CurrencyData;
}) {
    const [sheet, setSheet] = useState<SheetState>({
        open: false,
        departure: null,
        direction: directions[0]?.value ?? 'in',
    });
    const [togglingIds, setTogglingIds] = useState<Set<number>>(new Set());
    const [deleting, setDeleting] = useState<Departure | null>(null);
    const [turningOff, setTurningOff] = useState<Departure | null>(null);
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.code,
    });

    const openAdd = (direction: string) =>
        setSheet({ open: true, departure: null, direction });
    const openEdit = (departure: Departure) =>
        setSheet({ open: true, departure, direction: departure.direction });

    const toggleActive = (departure: Departure, active: boolean) => {
        const setToggling = (toggling: boolean) => {
            setTogglingIds((current) => {
                const next = new Set(current);

                if (toggling) {
                    next.add(departure.id);
                } else {
                    next.delete(departure.id);
                }

                return next;
            });
        };

        router
            .optimistic<{ departures: Departure[] }>((props) => ({
                departures: props.departures.map((item) =>
                    item.id === departure.id ? { ...item, active } : item,
                ),
            }))
            .put(
                update.url({ boatDeparture: departure.id }),
                { ...formValues(departure), active },
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

    // Turning off a departure that upcoming bookings use asks first. Everything else toggles at once.
    const requestToggle = (departure: Departure, active: boolean) => {
        if (!active && departure.upcomingBookings > 0) {
            setTurningOff(departure);

            return;
        }

        toggleActive(departure, active);
    };

    return (
        <>
            <Head title="Boats" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Boats"
                    description="When each boat leaves and arrives. Guests pick a departure on their booking."
                />

                <BoatSettingsTabs activeTab="departures">
                    <div className="flex flex-col gap-8">
                        {directions.map((direction) => {
                            const rows = departures.filter(
                                (departure) =>
                                    departure.direction === direction.value,
                            );

                            return (
                                <section
                                    key={direction.value}
                                    className="flex flex-col gap-3"
                                >
                                    <div className="flex items-center justify-between gap-4">
                                        <h2 className="text-base font-medium">
                                            {direction.label}
                                        </h2>
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                openAdd(direction.value)
                                            }
                                        >
                                            <Plus data-icon="inline-start" />
                                            Add departure
                                        </Button>
                                    </div>

                                    <BoatDepartureTable
                                        departures={rows}
                                        mealPeriods={mealPeriods}
                                        emptyTitle={`No ${direction.label.toLowerCase()} departures yet.`}
                                        money={money}
                                        togglingIds={togglingIds}
                                        onToggleActive={requestToggle}
                                        onEdit={openEdit}
                                        onDelete={setDeleting}
                                    />
                                </section>
                            );
                        })}
                    </div>
                </BoatSettingsTabs>
            </div>

            <BoatDepartureSheet
                open={sheet.open}
                onOpenChange={(open) =>
                    setSheet((current) => ({ ...current, open }))
                }
                departure={sheet.departure}
                defaultDirection={sheet.direction}
                directions={directions}
                fares={fares}
                mealPeriods={mealPeriods}
                currency={currency}
            />

            <AlertDialog
                open={turningOff !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setTurningOff(null);
                    }
                }}
            >
                <AlertDialogContent>
                    {turningOff && (
                        <Form
                            {...update.form({ boatDeparture: turningOff.id })}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setTurningOff(null)}
                        >
                            {({ processing }) => (
                                <>
                                    {Object.entries({
                                        ...formValues(turningOff),
                                        active: false,
                                    }).map(([name, value]) => (
                                        <input
                                            key={name}
                                            type="hidden"
                                            name={name}
                                            value={String(value)}
                                        />
                                    ))}
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>
                                            Turn off {turningOff.leavesAt}{' '}
                                            {turningOff.direction === 'in'
                                                ? 'boat in'
                                                : 'boat out'}
                                            ?
                                        </AlertDialogTitle>
                                        <AlertDialogDescription>
                                            {turningOff.upcomingBookings === 1
                                                ? '1 upcoming booking uses'
                                                : `${turningOff.upcomingBookings} upcoming bookings use`}{' '}
                                            the {turningOff.leavesAt}{' '}
                                            {turningOff.direction === 'in'
                                                ? 'boat in'
                                                : 'boat out'}
                                            . They keep it. New bookings cannot
                                            choose it.
                                        </AlertDialogDescription>
                                    </AlertDialogHeader>
                                    <AlertDialogFooter>
                                        <AlertDialogCancel>
                                            Cancel
                                        </AlertDialogCancel>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing && (
                                                <Spinner data-icon="inline-start" />
                                            )}
                                            Turn off
                                        </Button>
                                    </AlertDialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog
                open={deleting !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleting(null);
                    }
                }}
            >
                <AlertDialogContent>
                    {deleting && (
                        <Form
                            {...destroy.form({ boatDeparture: deleting.id })}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setDeleting(null)}
                        >
                            {({ processing }) => (
                                <>
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>
                                            Delete {deleting.leavesAt}{' '}
                                            {deleting.direction === 'in'
                                                ? 'boat in'
                                                : 'boat out'}
                                            ?
                                        </AlertDialogTitle>
                                        <AlertDialogDescription>
                                            The departure is removed from the
                                            list.
                                        </AlertDialogDescription>
                                    </AlertDialogHeader>
                                    <AlertDialogFooter>
                                        <AlertDialogCancel>
                                            Cancel
                                        </AlertDialogCancel>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing && (
                                                <Spinner data-icon="inline-start" />
                                            )}
                                            Delete departure
                                        </Button>
                                    </AlertDialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

BoatDeparturesPage.layout = {
    breadcrumbs: [{ title: 'Boats', href: boats() }],
};
