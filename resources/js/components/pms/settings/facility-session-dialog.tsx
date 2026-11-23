import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { store, update } from '@/routes/settings/facilities/sessions';

type Session = App.Data.Facilities.FacilitySessionData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    facilityId: number;
    /** The session being edited, or null to add one. */
    session: Session | null;
};

function SessionForm({ open, onOpenChange, facilityId, session }: Props) {
    const form = useForm({
        starts_at: session?.startsAt ?? '',
        ends_at: session?.endsAt ?? '',
    });
    const { data, errors, setData } = form;

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (session === null) {
            form.post(store.url({ facility: facilityId }), options);
        } else {
            form.put(
                update.url({ facility: facilityId, session: session.id }),
                options,
            );
        }
    };

    return (
        <FormOverlay
            variant="dialog"
            size="sm"
            open={open}
            onOpenChange={onOpenChange}
            title={session === null ? 'Add session' : 'Edit session'}
            errors={errors}
            dirty={form.isDirty}
            onSubmit={submit}
            submitLabel={session === null ? 'Add session' : 'Save'}
            processing={form.processing}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <Field data-invalid={!!errors.starts_at}>
                    <FieldLabel htmlFor="facility-session-from">
                        From
                    </FieldLabel>
                    <Input
                        id="facility-session-from"
                        type="time"
                        placeholder="e.g. 20:00"
                        value={data.starts_at}
                        onChange={(event) =>
                            setData('starts_at', event.target.value)
                        }
                        aria-invalid={!!errors.starts_at}
                        autoFocus
                    />
                    <FieldError>{errors.starts_at}</FieldError>
                </Field>
                <Field data-invalid={!!errors.ends_at}>
                    <FieldLabel htmlFor="facility-session-to">To</FieldLabel>
                    <Input
                        id="facility-session-to"
                        type="time"
                        placeholder="e.g. 22:00"
                        value={data.ends_at}
                        onChange={(event) =>
                            setData('ends_at', event.target.value)
                        }
                        aria-invalid={!!errors.ends_at}
                    />
                    <FieldError>{errors.ends_at}</FieldError>
                </Field>
            </div>
        </FormOverlay>
    );
}

/**
 * Adds or edits one session of a Shared facility. Keyed on each opening, so it always starts clean.
 */
export function FacilitySessionDialog(props: Props) {
    return <SessionForm key={useFreshOnOpen(props.open)} {...props} />;
}
