import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { store } from '@/routes/boats/sailings';

type Boat = App.Data.Boats.BoatData;
type CrewMember = App.Data.Boats.BoatCrewMemberData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    date: string;
    /** The date in words for the title, for example "11 Nov". */
    dateLabel: string;
    boats: Boat[];
    crew: CrewMember[];
};

const DIRECTION_OPTIONS: EnumOption[] = [
    { value: 'in', label: 'Boat in' },
    { value: 'out', label: 'Boat out' },
];

/**
 * Add trip or job: for now an extra guest trip only (`AddExtraSailing`). The Boat job type joins this Dialog later as a
 * Type choice above Direction, with its own fields; the extra trip fields below are the ones shown for `extra`.
 */
export function BoatTripDialog(props: Props) {
    return <BoatTripForm key={useFreshOnOpen(props.open)} {...props} />;
}

function BoatTripForm({
    open,
    onOpenChange,
    date,
    dateLabel,
    boats,
    crew,
}: Props) {
    const form = useForm({
        date,
        direction: '',
        leaves_at: '',
        arrives_at: '',
        pickup_point: '',
        boat_id: '',
        in_charge_id: '',
        assistant_ids: [] as string[],
    });

    const people = crew.filter((member) => member.active);
    const boatOptions: EnumOption[] = boats
        .filter((boat) => boat.active)
        .map((boat) => ({ value: String(boat.id), label: boat.name }));
    const inChargeOptions: EnumOption[] = people.map((member) => ({
        value: String(member.id),
        label: member.name,
    }));
    const assistantOptions = people
        .filter((member) => String(member.id) !== form.data.in_charge_id)
        .map((member) => ({ value: String(member.id), label: member.name }));

    const submit = () =>
        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });

    return (
        <FormOverlay
            variant="dialog"
            size="md"
            open={open}
            onOpenChange={onOpenChange}
            title={`Add trip or job · ${dateLabel}`}
            description="An extra boat trip that is not on the timetable. It has no fare."
            errors={form.errors}
            onSubmit={submit}
            submitLabel="Add"
            processing={form.processing}
            submitDisabled={
                form.data.direction === '' ||
                form.data.leaves_at === '' ||
                form.data.arrives_at === ''
            }
        >
            <Field data-invalid={!!form.errors.direction}>
                <FieldLabel htmlFor="trip-direction">Direction</FieldLabel>
                <EnumSelect
                    id="trip-direction"
                    options={DIRECTION_OPTIONS}
                    placeholder="Choose a direction"
                    value={form.data.direction}
                    onValueChange={(value) => form.setData('direction', value)}
                    aria-invalid={!!form.errors.direction}
                />
                <FieldError>{form.errors.direction}</FieldError>
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field data-invalid={!!form.errors.leaves_at}>
                    <FieldLabel htmlFor="trip-leaves">Leaves</FieldLabel>
                    <Input
                        id="trip-leaves"
                        type="time"
                        placeholder="e.g. 13:00"
                        value={form.data.leaves_at}
                        onChange={(event) =>
                            form.setData('leaves_at', event.target.value)
                        }
                        aria-invalid={!!form.errors.leaves_at}
                    />
                    <FieldError>{form.errors.leaves_at}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.arrives_at}>
                    <FieldLabel htmlFor="trip-arrives">Arrives</FieldLabel>
                    <Input
                        id="trip-arrives"
                        type="time"
                        placeholder="e.g. 13:45"
                        value={form.data.arrives_at}
                        onChange={(event) =>
                            form.setData('arrives_at', event.target.value)
                        }
                        aria-invalid={!!form.errors.arrives_at}
                    />
                    <FieldError>{form.errors.arrives_at}</FieldError>
                </Field>
            </div>
            <Field data-invalid={!!form.errors.pickup_point}>
                <FieldLabel htmlFor="trip-pickup">
                    Pick-up point (optional)
                </FieldLabel>
                <Input
                    id="trip-pickup"
                    placeholder="e.g. Jeti Kuala Besut"
                    maxLength={255}
                    value={form.data.pickup_point}
                    onChange={(event) =>
                        form.setData('pickup_point', event.target.value)
                    }
                    aria-invalid={!!form.errors.pickup_point}
                />
                <FieldError>{form.errors.pickup_point}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.boat_id}>
                <FieldLabel htmlFor="trip-boat">Boat</FieldLabel>
                <EnumSelect
                    id="trip-boat"
                    options={boatOptions}
                    allLabel="No boat"
                    placeholder="Choose a boat"
                    value={form.data.boat_id}
                    onValueChange={(value) => form.setData('boat_id', value)}
                    aria-invalid={!!form.errors.boat_id}
                />
                <FieldError>{form.errors.boat_id}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.in_charge_id}>
                <FieldLabel htmlFor="trip-in-charge">In charge</FieldLabel>
                <EnumSelect
                    id="trip-in-charge"
                    options={inChargeOptions}
                    allLabel="Nobody"
                    placeholder="Choose who is in charge"
                    value={form.data.in_charge_id}
                    onValueChange={(value) =>
                        form.setData((data) => ({
                            ...data,
                            in_charge_id: value,
                            assistant_ids: data.assistant_ids.filter(
                                (id) => id !== value,
                            ),
                        }))
                    }
                    aria-invalid={!!form.errors.in_charge_id}
                />
                <FieldError>{form.errors.in_charge_id}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.assistant_ids}>
                <FieldLabel htmlFor="trip-assistants">Assistants</FieldLabel>
                <MultiCombobox
                    id="trip-assistants"
                    name="assistant_ids"
                    items={assistantOptions}
                    value={form.data.assistant_ids}
                    onValueChange={(value) =>
                        form.setData('assistant_ids', value)
                    }
                    placeholder="Choose assistants, e.g. Razak"
                    emptyLabel={
                        crew.length === 0
                            ? 'No crew yet. Add one in Settings, Boats, Crew.'
                            : 'No matching crew.'
                    }
                    invalid={!!form.errors.assistant_ids}
                />
                <FieldError>{form.errors.assistant_ids}</FieldError>
            </Field>
        </FormOverlay>
    );
}
