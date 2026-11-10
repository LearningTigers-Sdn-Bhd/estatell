import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { save, store } from '@/routes/boats/sailings';

type Group = App.Data.Boats.BoatBoardGroupData;
type Boat = App.Data.Boats.BoatData;
type CrewMember = App.Data.Boats.BoatCrewMemberData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** `change` saves the boat and crew of the group's sailing. `add` adds another boat to the group's departure. */
    mode: 'change' | 'add';
    group: Group;
    date: string;
    /** The date in words for the title, for example "11 Nov". */
    dateLabel: string;
    boats: Boat[];
    crew: CrewMember[];
};

/**
 * Change boat and crew: the Boat, the person In charge and the Assistants of one sailing, saved through `SaveSailing`.
 * The same Dialog adds a boat to a departure (`AddSailingBoat`), which needs a boat. The server refuses a boat or crew
 * member that is unavailable, and that shows as a toast.
 */
export function BoatSailingDialog(props: Props) {
    return <BoatSailingForm key={useFreshOnOpen(props.open)} {...props} />;
}

function BoatSailingForm({
    open,
    onOpenChange,
    mode,
    group,
    date,
    dateLabel,
    boats,
    crew,
}: Props) {
    const isAdd = mode === 'add';
    const form = useForm({
        boat_departure_id: group.departureId,
        boat_sailing_id: isAdd ? null : group.sailingId,
        date,
        boat_id: isAdd || group.boatId === null ? '' : String(group.boatId),
        in_charge_id:
            isAdd || group.inChargeId === null ? '' : String(group.inChargeId),
        assistant_ids: isAdd ? [] : group.assistantIds.map(String),
    });

    const current = new Set(
        isAdd
            ? []
            : [
                  ...(group.inChargeId === null ? [] : [group.inChargeId]),
                  ...group.assistantIds,
              ],
    );
    const people = crew.filter(
        (member) => member.active || current.has(member.id),
    );
    const personLabel = (member: CrewMember) =>
        member.active ? member.name : `${member.name} (unavailable)`;
    const boatOptions: EnumOption[] = boats
        .filter((boat) => boat.active || boat.id === group.boatId)
        .map((boat) => ({
            value: String(boat.id),
            label: boat.active ? boat.name : `${boat.name} (unavailable)`,
        }));
    const inChargeOptions: EnumOption[] = people.map((member) => ({
        value: String(member.id),
        label: personLabel(member),
        description: member.phone ?? undefined,
    }));
    const assistantOptions = people
        .filter((member) => String(member.id) !== form.data.in_charge_id)
        .map((member) => ({
            value: String(member.id),
            label: personLabel(member),
            description: member.phone ?? undefined,
        }));

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (isAdd) {
            form.post(store.url(), options);
        } else {
            form.put(save.url(), options);
        }
    };

    return (
        <FormOverlay
            variant="dialog"
            size="sm"
            open={open}
            onOpenChange={onOpenChange}
            title={`${isAdd ? 'Add boat' : 'Change boat and crew'} · ${group.title} · ${dateLabel}`}
            errors={form.errors}
            onSubmit={submit}
            submitLabel={isAdd ? 'Add boat' : 'Save'}
            processing={form.processing}
            submitDisabled={isAdd && form.data.boat_id === ''}
        >
            <Field data-invalid={!!form.errors.boat_id}>
                <FieldLabel htmlFor="sailing-boat">Boat</FieldLabel>
                <EnumSelect
                    id="sailing-boat"
                    options={boatOptions}
                    allLabel={isAdd ? undefined : 'No boat'}
                    placeholder="Choose a boat"
                    value={form.data.boat_id}
                    onValueChange={(value) => form.setData('boat_id', value)}
                    aria-invalid={!!form.errors.boat_id}
                />
                <FieldError>{form.errors.boat_id}</FieldError>
            </Field>
            <Field data-invalid={!!form.errors.in_charge_id}>
                <FieldLabel htmlFor="sailing-in-charge">In charge</FieldLabel>
                <EnumSelect
                    id="sailing-in-charge"
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
                <FieldLabel htmlFor="sailing-assistants">Assistants</FieldLabel>
                <MultiCombobox
                    id="sailing-assistants"
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
