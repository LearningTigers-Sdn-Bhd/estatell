import { router, useForm, useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { toast } from '@/components/ui/toast';
import { move as moveRoute, movePreview } from '@/routes/reservations/units';
import { placeLabel } from './stay-places';
import type { TimelineMove } from './use-timeline-drag';

type Room = App.Data.StayView.RoomRowData;
type Preview = App.Data.Reservations.UnitMovePreviewData;

type Props = {
    move: TimelineMove | null;
    /** The row the bar was dropped on. */
    target: Room | undefined;
    /** The row the unit is in on the first night, when it has one. */
    current: Room | undefined;
    businessDate: string;
    onClose: () => void;
};

/** The props after the save, so the grid shows the move. */
const RELOAD = ['stays', 'blocks', 'rooms'];

function MoveForm({
    move,
    target,
    current,
    businessDate,
    onClose,
}: Props & { move: TimelineMove; target: Room }) {
    const { stay, nights, drop } = move;
    const isBed = target.bedId !== null;
    const noun = isBed ? 'bed' : 'room';
    const form = useForm({ from_night: drop.fromNight, reason: '' });
    const { data, errors } = form;
    const http = useHttp<Record<string, string>, Preview>('post', '', {});
    const [preview, setPreview] = useState<Preview | null>(null);
    const targetId = isBed ? target.bedId : target.roomId;
    const targetLabel = isBed
        ? placeLabel(target)
        : `Room ${target.roomNumber}`;
    const isFree =
        preview === null || preview.units.some((unit) => unit.id === targetId);

    useEffect(() => {
        if (data.from_night === '') {
            return;
        }

        let live = true;

        http.transform(() => ({
            from_night: data.from_night,
            room_type_id: String(target.roomTypeId),
        }));
        http.post(
            movePreview.url({
                reservation: stay.reservationId,
                unit: stay.unitId,
            }),
        )
            .then((answer) => live && setPreview(answer))
            .catch(() => live && setPreview(null));

        return () => {
            live = false;
        };
        // The preview depends only on the night and the row.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.from_night, target.roomTypeId, stay.unitId]);

    const submit = () => {
        form.transform((values) => ({
            from_night: values.from_night,
            [isBed ? 'bed_id' : 'room_id']: targetId,
            reason: values.reason,
        }));
        form.post(
            moveRoute.url({
                reservation: stay.reservationId,
                unit: stay.unitId,
            }),
            {
                preserveScroll: true,
                preserveState: true,
                only: RELOAD,
                onSuccess: onClose,
                onError: (failed) => {
                    const taken = failed.room_id ?? failed.bed_id;

                    if (taken) {
                        toast.add({ title: taken, type: 'error' });
                        onClose();
                        router.reload({ only: RELOAD });
                    }
                },
            },
        );
    };

    const isDirtyTonight =
        target.housekeepingStatus === 'dirty' &&
        data.from_night === businessDate;
    const reasonRequired = preview?.reasonRequired ?? true;

    return (
        <FormOverlay
            variant="dialog"
            open
            onOpenChange={(open) => (open ? undefined : onClose())}
            title={`Move to ${targetLabel}`}
            description={
                <>
                    {stay.guestLabel ?? 'No guest named'} ·{' '}
                    {current ? `from ${placeLabel(current)} · ` : ''}
                    <DateText date={stay.arrivalDate} /> →{' '}
                    <DateText date={stay.departureDate} />
                </>
            }
            errors={errors}
            onSubmit={submit}
            submitLabel={`Move ${noun}`}
            processing={form.processing}
            submitDisabled={
                preview === null ||
                preview.error !== null ||
                !isFree ||
                (reasonRequired && data.reason.trim() === '')
            }
        >
            <Field data-invalid={!!errors.from_night || !isFree}>
                <FieldLabel htmlFor="timeline-move-from_night">From</FieldLabel>
                {drop.isRoomTypeChange ? (
                    <FieldDescription>
                        The whole stay, from{' '}
                        <DateText date={stay.arrivalDate} />:{' '}
                        {target.roomTypeName} is another room type. The booked
                        prices stay.
                    </FieldDescription>
                ) : (
                    <>
                        <DatePicker
                            id="timeline-move-from_night"
                            value={data.from_night}
                            min={nights.first}
                            max={nights.last}
                            onChange={(value) =>
                                form.setData('from_night', value)
                            }
                            aria-invalid={!!errors.from_night || !isFree}
                        />
                        <FieldDescription>
                            The first night in {targetLabel}. Earlier nights
                            stay as they are.
                        </FieldDescription>
                    </>
                )}
                {!isFree && (
                    <FieldError>
                        {targetLabel} is not free from this night to the
                        departure.
                    </FieldError>
                )}
                {preview?.error != null && (
                    <FieldError>{preview.error}</FieldError>
                )}
                <FieldError>{errors.from_night}</FieldError>
            </Field>
            {isDirtyTonight && (
                <p className="text-sm text-warning-text">
                    {targetLabel} is Dirty. It needs cleaning before the guest
                    moves in tonight.
                </p>
            )}
            <Field data-invalid={!!errors.reason}>
                <FieldLabel htmlFor="timeline-move-reason">Reason</FieldLabel>
                <Textarea
                    id="timeline-move-reason"
                    value={data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    maxLength={500}
                    rows={2}
                    placeholder={
                        reasonRequired
                            ? 'e.g. Guest asked for a quieter room'
                            : 'e.g. Near the lift (optional for a first assignment)'
                    }
                    aria-invalid={!!errors.reason}
                />
                <FieldError>{errors.reason}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/**
 * The confirm Dialog after a stay bar is dropped on another row. Nothing is saved before Move. It sends `MoveUnit`
 * through the same route as Move room on the reservation page, and checks the row again with the preview whenever
 * the first night changes. A room that was taken meanwhile closes the Dialog with a toast and reloads the grid.
 */
export function TimelineMoveDialog(props: Props) {
    const key = useFreshOnOpen(props.move !== null);

    if (props.move === null || props.target === undefined) {
        return null;
    }

    return (
        <MoveForm
            key={key}
            {...props}
            move={props.move}
            target={props.target}
        />
    );
}
