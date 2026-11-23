import { useForm } from '@inertiajs/react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Badge } from '@/components/ui/badge';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { store, update } from '@/routes/settings/facilities/sessions';

type Session = App.Data.Facilities.FacilitySessionData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    facilityId: number;
    /** The session being edited, or null to add one or a range. */
    session: Session | null;
    /** The facility's sessions, so the preview can show which ones it skips. */
    sessions: Session[];
    sessionLengths: EnumOption[];
};

/** `HH:MM` as minutes after midnight, or null when not a full time yet. */
function minutes(time: string): number | null {
    const match = /^(\d{2}):(\d{2})$/.exec(time);

    return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

function clock(total: number): string {
    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

/**
 * The preview of Add sessions, for display only: the server splits the range again when it saves
 * (`FacilitySessionSlots`). A time left over at the end is dropped.
 */
function previewSlots(
    startsAt: string,
    endsAt: string,
    length: string,
): { startsAt: string; endsAt: string }[] {
    const from = minutes(startsAt);
    const to = minutes(endsAt);

    if (from === null || to === null || to <= from) {
        return [];
    }

    const step = length === 'whole' ? to - from : Number(length);
    const slots: { startsAt: string; endsAt: string }[] = [];

    for (let start = from; step > 0 && start + step <= to; start += step) {
        slots.push({ startsAt: clock(start), endsAt: clock(start + step) });
    }

    return slots;
}

function SessionForm({
    open,
    onOpenChange,
    facilityId,
    session,
    sessions,
    sessionLengths,
}: Props) {
    const adding = session === null;
    const form = useForm({
        starts_at: session?.startsAt ?? '',
        ends_at: session?.endsAt ?? '',
        length: 'whole',
    });
    const { data, errors, setData } = form;
    const existing = new Set(
        sessions.map((one) => `${one.startsAt}-${one.endsAt}`),
    );
    const slots = adding
        ? previewSlots(data.starts_at, data.ends_at, data.length)
        : [];
    const newCount = slots.filter(
        (slot) => !existing.has(`${slot.startsAt}-${slot.endsAt}`),
    ).length;

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (session === null) {
            form.post(store.url({ facility: facilityId }), options);
        } else {
            form.transform(({ starts_at, ends_at }) => ({
                starts_at,
                ends_at,
            }));
            form.put(
                update.url({ facility: facilityId, session: session.id }),
                options,
            );
        }
    };

    return (
        <FormOverlay
            variant="dialog"
            size={adding ? 'md' : 'sm'}
            open={open}
            onOpenChange={onOpenChange}
            title={adding ? 'Add sessions' : 'Edit session'}
            description={
                adding
                    ? 'Give the opening hours and how long each session lasts. Every session in between is added.'
                    : undefined
            }
            errors={errors}
            dirty={form.isDirty}
            onSubmit={submit}
            submitLabel={
                !adding
                    ? 'Save'
                    : newCount > 1
                      ? `Add ${newCount} sessions`
                      : 'Add session'
            }
            processing={form.processing}
        >
            <div
                className={
                    adding
                        ? 'grid gap-4 sm:grid-cols-3'
                        : 'grid gap-4 sm:grid-cols-2'
                }
            >
                <Field data-invalid={!!errors.starts_at}>
                    <FieldLabel htmlFor="facility-session-from">
                        From
                    </FieldLabel>
                    <Input
                        id="facility-session-from"
                        type="time"
                        placeholder="e.g. 08:00"
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
                {adding && (
                    <Field data-invalid={!!errors.length}>
                        <FieldLabel htmlFor="facility-session-length">
                            Each lasts
                        </FieldLabel>
                        <EnumSelect
                            id="facility-session-length"
                            name="length"
                            options={sessionLengths}
                            value={data.length}
                            onValueChange={(value) => setData('length', value)}
                            aria-invalid={!!errors.length}
                        />
                        <FieldError>{errors.length}</FieldError>
                    </Field>
                )}
            </div>
            {adding && slots.length > 0 && (
                <div className="flex flex-col gap-2">
                    <FieldDescription>
                        {newCount === 0
                            ? 'All of these sessions are already added.'
                            : `Adds ${newCount} ${newCount === 1 ? 'session' : 'sessions'}${newCount < slots.length ? '. Sessions already added are skipped' : ''}.`}
                    </FieldDescription>
                    <div className="flex flex-wrap gap-1.5">
                        {slots.map((slot) => {
                            const skipped = existing.has(
                                `${slot.startsAt}-${slot.endsAt}`,
                            );

                            return (
                                <Badge
                                    key={`${slot.startsAt}-${slot.endsAt}`}
                                    variant={skipped ? 'outline' : 'secondary'}
                                    className="tabular-nums"
                                >
                                    {slot.startsAt}–{slot.endsAt}
                                    {skipped && ' (added)'}
                                </Badge>
                            );
                        })}
                    </div>
                </div>
            )}
        </FormOverlay>
    );
}

/**
 * Adds sessions (one, or a range split into back-to-back sessions) or edits one session of a facility. Keyed on each opening, so it always starts clean.
 */
export function FacilitySessionDialog(props: Props) {
    return <SessionForm key={useFreshOnOpen(props.open)} {...props} />;
}
