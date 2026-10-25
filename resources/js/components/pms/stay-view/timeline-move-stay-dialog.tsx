import { router, useForm } from '@inertiajs/react';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { toast } from '@/components/ui/toast';
import { moveStay as moveStayRoute } from '@/routes/reservations/units';
import { daysBetween } from './resize-target';
import { placeLabel } from './stay-places';
import type { TimelineStayMove } from './use-timeline-drag';

type Room = App.Data.StayView.RoomRowData;

type Props = {
    change: TimelineStayMove | null;
    /** The row the bar was dropped on. */
    target: Room | undefined;
    /** The row the unit is in on its arrival night, when it has one. */
    current: Room | undefined;
    currency: App.Data.Property.CurrencyData | null;
    canOverrideRestrictions: boolean;
    onClose: () => void;
};

/** The props after the save, so the grid shows the new dates and room. */
const RELOAD = ['stays', 'blocks', 'rooms'];

/** The fields the Dialog has. A refusal on any other field closes it with a toast. */
const OWN_FIELDS = ['reason', 'restriction_override_reason'];

function Change({ old, now }: { old: string; now: string }) {
    return old === now ? (
        <DateText date={now} />
    ) : (
        <span className="flex items-center gap-1.5">
            <span className="text-muted-foreground line-through">
                <DateText date={old} />
            </span>
            <span aria-hidden>→</span>
            <span className="font-medium">
                <DateText date={now} />
            </span>
        </span>
    );
}

function MoveStayForm({
    change,
    target,
    current,
    currency,
    canOverrideRestrictions,
    onClose,
}: Props & { change: TimelineStayMove; target: Room }) {
    const { stay, arrivalDate, departureDate, preview } = change;
    const dates = preview.dates;
    const isBed = target.bedId !== null;
    const targetLabel = placeLabel(target);
    const currentLabel = current ? placeLabel(current) : 'Not assigned';
    const form = useForm({ reason: '', restriction_override_reason: '' });
    const { data, errors } = form;
    const needsOverride = !!dates?.restricted && canOverrideRestrictions;
    const difference = Number(dates?.difference ?? 0);
    const nights = daysBetween(arrivalDate, departureDate);

    const submit = () => {
        form.transform((values) => ({
            arrival_date: arrivalDate,
            departure_date: departureDate,
            [isBed ? 'bed_id' : 'room_id']: isBed
                ? target.bedId
                : target.roomId,
            reason: values.reason,
            restriction_override_reason: needsOverride
                ? values.restriction_override_reason
                : '',
        }));
        form.post(
            moveStayRoute.url({
                reservation: stay.reservationId,
                unit: stay.unitId,
            }),
            {
                preserveScroll: true,
                preserveState: true,
                only: RELOAD,
                onSuccess: onClose,
                onError: (failed) => {
                    const message = Object.entries(failed).find(
                        ([field]) => !OWN_FIELDS.includes(field),
                    )?.[1];

                    // A refusal with no field of its own (room just taken, sold out, no price) puts the bar back.
                    if (message) {
                        toast.add({ title: message, type: 'error' });
                        onClose();
                        router.reload({ only: RELOAD });
                    }
                },
            },
        );
    };

    return (
        <FormOverlay
            variant="dialog"
            open
            onOpenChange={(open) => (open ? undefined : onClose())}
            title="Move stay"
            description={
                <>
                    {stay.guestLabel ?? 'No guest named'} · {stay.reference}
                </>
            }
            errors={errors}
            onSubmit={submit}
            submitLabel="Move stay"
            processing={form.processing}
            submitDisabled={
                (preview.reasonRequired && data.reason.trim() === '') ||
                (needsOverride &&
                    data.restriction_override_reason.trim() === '')
            }
        >
            <dl className="flex flex-col gap-1 text-sm">
                <div className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">
                        {isBed ? 'Bed' : 'Room'}
                    </dt>
                    <dd>
                        {currentLabel === targetLabel ? (
                            targetLabel
                        ) : (
                            <span className="flex items-center gap-1.5">
                                <span className="text-muted-foreground line-through">
                                    {currentLabel}
                                </span>
                                <span aria-hidden>→</span>
                                <span className="font-medium">
                                    {targetLabel}
                                </span>
                            </span>
                        )}
                    </dd>
                </div>
                <div className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">Arrival</dt>
                    <dd>
                        <Change old={stay.arrivalDate} now={arrivalDate} />
                    </dd>
                </div>
                <div className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">Departure</dt>
                    <dd>
                        <Change old={stay.departureDate} now={departureDate} />
                    </dd>
                </div>
                <div className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">Nights</dt>
                    <dd>{nights}</dd>
                </div>
            </dl>
            <dl className="flex flex-col gap-1 rounded-lg border p-3 text-sm">
                <div className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">Was</dt>
                    <dd>
                        <MoneyText
                            amount={dates?.previousTotal ?? '0'}
                            currency={currency}
                        />
                    </dd>
                </div>
                <div className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">Now</dt>
                    <dd>
                        <MoneyText
                            amount={dates?.newTotal ?? '0'}
                            currency={currency}
                        />
                    </dd>
                </div>
                <div className="flex justify-between gap-4 font-medium">
                    <dt>Difference</dt>
                    <dd>
                        {difference > 0 && '+ '}
                        {difference < 0 && '- '}
                        <MoneyText
                            amount={String(Math.abs(difference))}
                            currency={currency}
                        />
                    </dd>
                </div>
            </dl>
            <Field data-invalid={!!errors.reason}>
                <FieldLabel htmlFor="timeline-move-stay-reason">
                    Reason
                </FieldLabel>
                <Textarea
                    id="timeline-move-stay-reason"
                    value={data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    maxLength={500}
                    rows={2}
                    placeholder="e.g. Guest asked to arrive a day later in a sea-view room"
                    aria-invalid={!!errors.reason}
                />
                <FieldDescription>
                    {preview.reasonRequired
                        ? 'Required: the room changes.'
                        : 'Optional: the room stays the same.'}
                </FieldDescription>
                <FieldError>{errors.reason}</FieldError>
            </Field>
            {needsOverride && (
                <Field data-invalid={!!errors.restriction_override_reason}>
                    <FieldLabel htmlFor="timeline-move-stay-restriction_override_reason">
                        Override reason
                    </FieldLabel>
                    <Textarea
                        id="timeline-move-stay-restriction_override_reason"
                        value={data.restriction_override_reason}
                        onChange={(event) =>
                            form.setData(
                                'restriction_override_reason',
                                event.target.value,
                            )
                        }
                        maxLength={500}
                        rows={2}
                        placeholder="e.g. Regular guest, manager approved"
                        aria-invalid={!!errors.restriction_override_reason}
                    />
                    <FieldDescription>
                        The new nights break a rate restriction. A reason lets
                        you move the stay anyway. It is logged.
                    </FieldDescription>
                    <FieldError>
                        {errors.restriction_override_reason}
                    </FieldError>
                </Field>
            )}
        </FormOverlay>
    );
}

/**
 * The confirm Dialog after a Confirmed bar is dropped on other dates and a room or bed. Nothing is saved before Move
 * stay. It sends `MoveUnitStay`: the dates and the room in one step, so a refusal of either saves nothing. A refusal
 * with no field of its own closes it with a toast and reloads the grid.
 */
export function TimelineMoveStayDialog(props: Props) {
    const key = useFreshOnOpen(props.change !== null);

    if (props.change === null || props.target === undefined) {
        return null;
    }

    return (
        <MoveStayForm
            key={key}
            {...props}
            change={props.change}
            target={props.target}
        />
    );
}
