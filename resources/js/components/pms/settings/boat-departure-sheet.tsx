import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { fareLabel } from '@/components/pms/settings/boat-departure-table';
import { Badge } from '@/components/ui/badge';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { departureMeals } from '@/lib/departure-meals';
import { store, update } from '@/routes/settings/boats/departures';

type Departure = App.Data.Boats.BoatDepartureData;
type Fare = App.Data.Boats.BoatFareData;
type Boat = App.Data.Boats.BoatData;
type CrewMember = App.Data.Boats.BoatCrewMemberData;
type Option = { value: string; label: string };

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The departure being edited, or null to add one. */
    departure: Departure | null;
    /** The direction a new departure starts with, from the section's Add button. */
    defaultDirection: string;
    directions: Option[];
    fares: Fare[];
    boats: Boat[];
    crew: CrewMember[];
    /** The active meal periods, for the meals preview under the times. */
    mealPeriods: App.Data.Meals.MealPeriodData[];
    currency: App.Data.Property.CurrencyData;
};

type FormData = {
    direction: string;
    leaves_at: string;
    arrives_at: string;
    pickup_point: string;
    seats: string;
    charge_item_id: string;
    default_boat_id: string;
    in_charge_id: string;
    assistant_ids: string[];
    active: boolean;
};

/** The "No fare" choice, which the form sends as an empty fare. */
const NO_FARE = 'none';
const NO_DEFAULT_BOAT = 'none';
const NO_IN_CHARGE = 'none';

function toFormData(
    departure: Departure | null,
    defaultDirection: string,
): FormData {
    if (departure === null) {
        return {
            direction: defaultDirection,
            leaves_at: '',
            arrives_at: '',
            pickup_point: '',
            seats: '',
            charge_item_id: '',
            default_boat_id: '',
            in_charge_id: '',
            assistant_ids: [],
            active: true,
        };
    }

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
        default_boat_id:
            departure.defaultBoatId === null ||
            departure.defaultBoatId === undefined
                ? ''
                : String(departure.defaultBoatId),
        in_charge_id:
            departure.inChargeId === null ? '' : String(departure.inChargeId),
        assistant_ids: departure.assistantIds.map(String),
        active: departure.active,
    };
}

function BoatDepartureForm({
    open,
    onOpenChange,
    departure,
    defaultDirection,
    directions,
    fares,
    boats,
    crew,
    mealPeriods,
    currency,
}: Props) {
    const form = useForm<FormData>(toFormData(departure, defaultDirection));
    const { data, errors, setData } = form;
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.code,
    });
    // Only the property-side time moves the upcoming trips: Arrives for Boat in, Leaves for Boat out.
    const movesBookings =
        departure !== null &&
        departure.upcomingBookings > 0 &&
        (departure.direction === 'in'
            ? data.arrives_at !== departure.arrivesAt
            : data.leaves_at !== departure.leavesAt);

    // The meals preview follows the property-side time, so it waits until that time is filled in.
    const propertyTime =
        data.direction === 'in' ? data.arrives_at : data.leaves_at;
    const previewMeals = departureMeals(
        data.direction,
        data.leaves_at,
        data.arrives_at,
        mealPeriods,
    );

    // The current fare stays in the list even when it is unavailable, so an edit keeps it.
    const offeredFares = fares.filter(
        (fare) => fare.active || fare.id === departure?.chargeItemId,
    );

    if (
        departure?.fare &&
        !offeredFares.some((fare) => fare.id === departure.fare?.id)
    ) {
        offeredFares.push(departure.fare);
    }

    const fareOptions: EnumOption[] = [
        { value: NO_FARE, label: 'No fare' },
        ...offeredFares.map((fare) => ({
            value: String(fare.id),
            label: fareLabel(fare, money),
        })),
    ];

    const boatOptions: EnumOption[] = [
        { value: NO_DEFAULT_BOAT, label: 'No default boat' },
        ...boats.map((boat) => ({
            value: String(boat.id),
            label: boat.name,
        })),
    ];

    // Only available crew can be picked, plus the people already on this departure, so an edit does not drop them.
    const pickedIds = new Set([
        ...(departure?.assistantIds ?? []),
        ...(departure?.inChargeId ? [departure.inChargeId] : []),
    ]);
    const offeredCrew = crew.filter(
        (member) => member.active || pickedIds.has(member.id),
    );
    const crewLabel = (member: CrewMember) =>
        member.active ? member.name : `${member.name} (unavailable)`;
    const inChargeOptions: EnumOption[] = [
        { value: NO_IN_CHARGE, label: 'Nobody' },
        ...offeredCrew.map((member) => ({
            value: String(member.id),
            label: crewLabel(member),
            description: member.phone ?? undefined,
        })),
    ];
    const assistantOptions = offeredCrew
        .filter((member) => String(member.id) !== data.in_charge_id)
        .map((member) => ({
            value: String(member.id),
            label: crewLabel(member),
            description: member.phone ?? undefined,
        }));

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (departure) {
            form.put(update.url({ boatDeparture: departure.id }), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <FormOverlay
            variant="sheet"
            size="md"
            open={open}
            onOpenChange={onOpenChange}
            title={departure ? 'Edit departure' : 'Add departure'}
            description="A boat that runs every day at this time."
            errors={errors}
            dirty={form.isDirty}
            discardTitle="Discard this departure?"
            onSubmit={submit}
            submitLabel={departure ? 'Save departure' : 'Add departure'}
            processing={form.processing}
        >
            <FieldGroup>
                <FieldSet>
                    <FieldLegend variant="label">Direction</FieldLegend>
                    <RadioGroup
                        value={data.direction}
                        onValueChange={(value) => setData('direction', value)}
                        className="grid-cols-2"
                    >
                        {directions.map((option) => (
                            <div
                                key={option.value}
                                className="flex items-center gap-2"
                            >
                                <RadioGroupItem
                                    id={`boat-direction-${option.value}`}
                                    value={option.value}
                                />
                                <Label
                                    htmlFor={`boat-direction-${option.value}`}
                                >
                                    {option.label}
                                </Label>
                            </div>
                        ))}
                    </RadioGroup>
                    <FieldError>{errors.direction}</FieldError>
                </FieldSet>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={!!errors.leaves_at}>
                        <FieldLabel htmlFor="boat-departure-leaves">
                            Leaves
                        </FieldLabel>
                        <Input
                            id="boat-departure-leaves"
                            type="time"
                            value={data.leaves_at}
                            onChange={(event) =>
                                setData('leaves_at', event.target.value)
                            }
                            aria-invalid={!!errors.leaves_at}
                            autoFocus
                        />
                        <FieldError>{errors.leaves_at}</FieldError>
                    </Field>
                    <Field data-invalid={!!errors.arrives_at}>
                        <FieldLabel htmlFor="boat-departure-arrives">
                            Arrives
                        </FieldLabel>
                        <Input
                            id="boat-departure-arrives"
                            type="time"
                            value={data.arrives_at}
                            onChange={(event) =>
                                setData('arrives_at', event.target.value)
                            }
                            aria-invalid={!!errors.arrives_at}
                        />
                        <FieldError>{errors.arrives_at}</FieldError>
                    </Field>
                    {propertyTime !== '' && (
                        <FieldDescription
                            className="flex flex-wrap items-center gap-1 sm:col-span-2"
                            aria-live="polite"
                        >
                            {mealPeriods.length === 0 ? (
                                'No meal periods yet.'
                            ) : (
                                <>
                                    Meals:
                                    {previewMeals.length === 0
                                        ? ' none'
                                        : previewMeals.map((meal) => (
                                              <Badge
                                                  key={meal}
                                                  variant="outline"
                                              >
                                                  {meal}
                                              </Badge>
                                          ))}
                                </>
                            )}
                        </FieldDescription>
                    )}
                </div>
                <Field data-invalid={!!errors.pickup_point}>
                    <FieldLabel htmlFor="boat-departure-pickup">
                        Pick-up point
                    </FieldLabel>
                    <Input
                        id="boat-departure-pickup"
                        placeholder="e.g. Kuala Besut jetty"
                        maxLength={150}
                        value={data.pickup_point}
                        onChange={(event) =>
                            setData('pickup_point', event.target.value)
                        }
                        aria-invalid={!!errors.pickup_point}
                    />
                    <FieldError>{errors.pickup_point}</FieldError>
                </Field>
                <Field data-invalid={!!errors.seats}>
                    <FieldLabel htmlFor="boat-departure-seats">
                        Seats
                    </FieldLabel>
                    <Input
                        id="boat-departure-seats"
                        type="number"
                        inputMode="numeric"
                        min={1}
                        placeholder="e.g. 20"
                        value={data.seats}
                        onChange={(event) =>
                            setData('seats', event.target.value)
                        }
                        aria-invalid={!!errors.seats}
                    />
                    <FieldDescription>
                        Leave empty for no limit.
                    </FieldDescription>
                    <FieldError>{errors.seats}</FieldError>
                </Field>
                <Field data-invalid={!!errors.charge_item_id}>
                    <FieldLabel htmlFor="boat-departure-fare">Fare</FieldLabel>
                    <EnumSelect
                        id="boat-departure-fare"
                        name="charge_item_id"
                        options={fareOptions}
                        placeholder="Choose a fare"
                        value={data.charge_item_id || NO_FARE}
                        onValueChange={(value) =>
                            setData(
                                'charge_item_id',
                                value === NO_FARE ? '' : value,
                            )
                        }
                        aria-invalid={!!errors.charge_item_id}
                    />
                    <FieldDescription>
                        Applies to new boat trips only.
                    </FieldDescription>
                    <FieldError>
                        {errors.charge_item_id ?? errors.active}
                    </FieldError>
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={!!errors.default_boat_id}>
                        <FieldLabel htmlFor="boat-departure-default-boat">
                            Default boat
                        </FieldLabel>
                        <EnumSelect
                            id="boat-departure-default-boat"
                            name="default_boat_id"
                            options={boatOptions}
                            placeholder="Choose a boat"
                            value={data.default_boat_id || NO_DEFAULT_BOAT}
                            onValueChange={(value) =>
                                setData(
                                    'default_boat_id',
                                    value === NO_DEFAULT_BOAT ? '' : value,
                                )
                            }
                            aria-invalid={!!errors.default_boat_id}
                        />
                        <FieldDescription>
                            Prefills each date on the Boats board.
                        </FieldDescription>
                        <FieldError>{errors.default_boat_id}</FieldError>
                    </Field>
                </div>
                <Field data-invalid={!!errors.in_charge_id}>
                    <FieldLabel htmlFor="boat-departure-in-charge">
                        In charge
                    </FieldLabel>
                    {crew.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No crew yet. Add one on the Crew tab.
                        </p>
                    ) : (
                        <EnumSelect
                            id="boat-departure-in-charge"
                            name="in_charge_id"
                            options={inChargeOptions}
                            placeholder="Choose who is in charge"
                            value={data.in_charge_id || NO_IN_CHARGE}
                            onValueChange={(value) => {
                                const next =
                                    value === NO_IN_CHARGE ? '' : value;
                                setData((current) => ({
                                    ...current,
                                    in_charge_id: next,
                                    assistant_ids: current.assistant_ids.filter(
                                        (id) => id !== next,
                                    ),
                                }));
                            }}
                            aria-invalid={!!errors.in_charge_id}
                        />
                    )}
                    <FieldError>{errors.in_charge_id}</FieldError>
                </Field>
                <Field data-invalid={!!errors.assistant_ids}>
                    <FieldLabel htmlFor="boat-departure-assistants">
                        Assistants
                    </FieldLabel>
                    <MultiCombobox
                        id="boat-departure-assistants"
                        name="assistant_ids"
                        items={assistantOptions}
                        value={data.assistant_ids}
                        onValueChange={(value) =>
                            setData('assistant_ids', value)
                        }
                        placeholder="Choose assistants"
                        emptyLabel={
                            crew.length === 0
                                ? 'No crew yet. Add one on the Crew tab.'
                                : 'No matching crew.'
                        }
                        invalid={!!errors.assistant_ids}
                    />
                    <FieldDescription>
                        Prefill each date on the Boats board.
                    </FieldDescription>
                    <FieldError>
                        {errors.assistant_ids ?? errors['assistant_ids.0']}
                    </FieldError>
                </Field>
                {movesBookings && departure !== null && (
                    <FieldDescription>
                        {departure.upcomingBookings === 1
                            ? 'Updates the time on 1 upcoming booking.'
                            : `Updates the times on ${departure.upcomingBookings} upcoming bookings.`}
                    </FieldDescription>
                )}
            </FieldGroup>
        </FormOverlay>
    );
}

/**
 * Adds or edits one departure. The form is keyed on each opening, so it always starts from the row it was opened for.
 */
export function BoatDepartureSheet(props: Props) {
    return <BoatDepartureForm key={useFreshOnOpen(props.open)} {...props} />;
}
