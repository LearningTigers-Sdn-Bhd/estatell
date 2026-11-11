import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { store as storeJob, update as updateJob } from '@/routes/boats/jobs';
import { store } from '@/routes/boats/sailings';

type Boat = App.Data.Boats.BoatData;
type CrewMember = App.Data.Boats.BoatCrewMemberData;
type Job = App.Data.Boats.BoatJobData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    date: string;
    /** The date in words for the title, for example "11 Nov". */
    dateLabel: string;
    boats: Boat[];
    crew: CrewMember[];
    /** From `BoatJobType::options()`. */
    jobTypes: EnumOption[];
    /** The job to edit. Without it the Dialog adds an extra trip or a job. */
    job?: Job | null;
};

type TripType = 'extra' | 'job';

const DIRECTION_OPTIONS: EnumOption[] = [
    { value: 'in', label: 'Boat in' },
    { value: 'out', label: 'Boat out' },
];

/**
 * Add trip or job: an extra guest trip (`AddExtraSailing`) or a boat job (`SaveBoatJob`), picked with Type. Edit a job opens
 * the same Dialog with the job's values and no Type choice. A job has a Job type, Leaves and a Note, and no Direction,
 * Arrives or Pick-up point. See docs/24-boats/05-boats-board.md#trip-or-job.
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
    jobTypes,
    job = null,
}: Props) {
    const form = useForm({
        type: (job ? 'job' : 'extra') as TripType,
        date,
        direction: '',
        job_type: job?.jobType ?? '',
        leaves_at: job?.leavesAt ?? '',
        arrives_at: '',
        pickup_point: '',
        boat_id: job?.boatId ? String(job.boatId) : '',
        in_charge_id: job?.inChargeId ? String(job.inChargeId) : '',
        assistant_ids: (job?.assistantIds ?? []).map(String),
        note: job?.note ?? '',
    });

    const isJob = form.data.type === 'job';
    const keep =
        (id: number | null, ids: number[] = []) =>
        (value: { id: number; active: boolean }) =>
            value.active || value.id === id || ids.includes(value.id);
    const people = crew.filter(
        keep(job?.inChargeId ?? null, job?.assistantIds ?? []),
    );
    const boatOptions: EnumOption[] = boats
        .filter(keep(job?.boatId ?? null))
        .map((boat) => ({ value: String(boat.id), label: boat.name }));
    const inChargeOptions: EnumOption[] = people.map((member) => ({
        value: String(member.id),
        label: member.name,
        description: member.phone ?? undefined,
    }));
    const assistantOptions = people
        .filter((member) => String(member.id) !== form.data.in_charge_id)
        .map((member) => ({
            value: String(member.id),
            label: member.name,
            description: member.phone ?? undefined,
        }));

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (!isJob) {
            form.transform((data) => ({
                date: data.date,
                direction: data.direction,
                leaves_at: data.leaves_at,
                arrives_at: data.arrives_at,
                pickup_point: data.pickup_point,
                boat_id: data.boat_id,
                in_charge_id: data.in_charge_id,
                assistant_ids: data.assistant_ids,
            }));
            form.post(store.url(), options);

            return;
        }

        form.transform((data) => ({
            date: data.date,
            job_type: data.job_type,
            leaves_at: data.leaves_at,
            boat_id: data.boat_id,
            in_charge_id: data.in_charge_id,
            assistant_ids: data.assistant_ids,
            note: data.note,
        }));

        if (job) {
            form.put(updateJob.url({ sailing: job.id }), options);
        } else {
            form.post(storeJob.url(), options);
        }
    };

    return (
        <FormOverlay
            variant="dialog"
            size="md"
            open={open}
            onOpenChange={onOpenChange}
            title={
                job
                    ? `Edit boat job · ${dateLabel}`
                    : `Add trip or job · ${dateLabel}`
            }
            description={
                isJob
                    ? 'A boat and crew going out without guests, such as a diesel or maintenance run.'
                    : 'An extra boat trip that is not on the timetable. It has no fare.'
            }
            errors={form.errors}
            onSubmit={submit}
            submitLabel={job ? 'Save' : 'Add'}
            processing={form.processing}
            submitDisabled={
                isJob
                    ? form.data.job_type === '' ||
                      form.data.leaves_at === '' ||
                      (form.data.job_type === 'other' &&
                          form.data.note.trim() === '')
                    : form.data.direction === '' ||
                      form.data.leaves_at === '' ||
                      form.data.arrives_at === ''
            }
        >
            {!job && (
                <Field>
                    <FieldLabel>Type</FieldLabel>
                    <RadioGroup
                        value={form.data.type}
                        onValueChange={(value) =>
                            form.setData('type', value as TripType)
                        }
                        className="flex flex-wrap gap-x-6 gap-y-2"
                    >
                        <Field orientation="horizontal" className="w-auto">
                            <RadioGroupItem
                                value="extra"
                                id="trip-type-extra"
                            />
                            <FieldLabel
                                htmlFor="trip-type-extra"
                                className="font-normal"
                            >
                                Extra trip
                            </FieldLabel>
                        </Field>
                        <Field orientation="horizontal" className="w-auto">
                            <RadioGroupItem value="job" id="trip-type-job" />
                            <FieldLabel
                                htmlFor="trip-type-job"
                                className="font-normal"
                            >
                                Boat job
                            </FieldLabel>
                        </Field>
                    </RadioGroup>
                </Field>
            )}
            {isJob ? (
                <Field data-invalid={!!form.errors.job_type}>
                    <FieldLabel htmlFor="trip-job-type">Job</FieldLabel>
                    <EnumSelect
                        id="trip-job-type"
                        options={jobTypes}
                        placeholder="Choose a job"
                        value={form.data.job_type}
                        onValueChange={(value) =>
                            form.setData('job_type', value)
                        }
                        aria-invalid={!!form.errors.job_type}
                    />
                    <FieldError>{form.errors.job_type}</FieldError>
                </Field>
            ) : (
                <Field data-invalid={!!form.errors.direction}>
                    <FieldLabel htmlFor="trip-direction">Direction</FieldLabel>
                    <EnumSelect
                        id="trip-direction"
                        options={DIRECTION_OPTIONS}
                        placeholder="Choose a direction"
                        value={form.data.direction}
                        onValueChange={(value) =>
                            form.setData('direction', value)
                        }
                        aria-invalid={!!form.errors.direction}
                    />
                    <FieldError>{form.errors.direction}</FieldError>
                </Field>
            )}
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
                {!isJob && (
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
                )}
            </div>
            {!isJob && (
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
            )}
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
            {isJob && (
                <Field data-invalid={!!form.errors.note}>
                    <FieldLabel htmlFor="trip-note">
                        {form.data.job_type === 'other'
                            ? 'Note'
                            : 'Note (optional)'}
                    </FieldLabel>
                    <Input
                        id="trip-note"
                        placeholder="e.g. 4 drums, engine service at Semporna"
                        maxLength={500}
                        value={form.data.note}
                        onChange={(event) =>
                            form.setData('note', event.target.value)
                        }
                        aria-invalid={!!form.errors.note}
                    />
                    <FieldError>{form.errors.note}</FieldError>
                </Field>
            )}
        </FormOverlay>
    );
}
