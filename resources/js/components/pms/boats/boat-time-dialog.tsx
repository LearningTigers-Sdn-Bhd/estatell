import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { save } from '@/routes/boats/sailings';

type Group = App.Data.Boats.BoatBoardGroupData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    group: Group;
    date: string;
    /** The date in words for the title, for example "11 Nov". */
    dateLabel: string;
};

/**
 * Change time: Leaves and Arrives of one sailing for one date, saved through `SaveSailing`. A time equal to the timetable
 * goes back to the timetable. The sailing keeps its boat and crew, so the Dialog sends them as they are. Unit times follow
 * on the server.
 */
export function BoatTimeDialog(props: Props) {
    return <BoatTimeForm key={useFreshOnOpen(props.open)} {...props} />;
}

function BoatTimeForm({ open, onOpenChange, group, date, dateLabel }: Props) {
    const form = useForm({
        boat_departure_id: group.departureId,
        boat_sailing_id: group.sailingId,
        date,
        boat_id: group.boatId === null ? '' : String(group.boatId),
        in_charge_id: group.inChargeId === null ? '' : String(group.inChargeId),
        assistant_ids: group.assistantIds.map(String),
        sets_times: true,
        leaves_at: group.leavesAt ?? '',
        arrives_at: group.arrivesAt ?? '',
    });

    const submit = () =>
        form.put(save.url(), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });

    return (
        <FormOverlay
            variant="dialog"
            size="sm"
            open={open}
            onOpenChange={onOpenChange}
            title={`Change time · ${group.directionLabel} ${group.leavesAt} · ${dateLabel}`}
            errors={form.errors}
            onSubmit={submit}
            submitLabel="Save"
            processing={form.processing}
            submitDisabled={
                form.data.leaves_at === '' || form.data.arrives_at === ''
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field data-invalid={!!form.errors.leaves_at}>
                    <FieldLabel htmlFor="sailing-leaves">Leaves</FieldLabel>
                    <Input
                        id="sailing-leaves"
                        type="time"
                        placeholder="e.g. 09:30"
                        value={form.data.leaves_at}
                        onChange={(event) =>
                            form.setData('leaves_at', event.target.value)
                        }
                        aria-invalid={!!form.errors.leaves_at}
                        autoFocus
                    />
                    <FieldError>{form.errors.leaves_at}</FieldError>
                </Field>
                <Field data-invalid={!!form.errors.arrives_at}>
                    <FieldLabel htmlFor="sailing-arrives">Arrives</FieldLabel>
                    <Input
                        id="sailing-arrives"
                        type="time"
                        placeholder="e.g. 10:15"
                        value={form.data.arrives_at}
                        onChange={(event) =>
                            form.setData('arrives_at', event.target.value)
                        }
                        aria-invalid={!!form.errors.arrives_at}
                    />
                    <FieldError>{form.errors.arrives_at}</FieldError>
                </Field>
            </div>
        </FormOverlay>
    );
}
