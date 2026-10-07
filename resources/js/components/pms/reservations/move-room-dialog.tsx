import { useForm, usePage } from '@inertiajs/react';
import { DatePicker } from '@/components/pms/date-picker';
import { DateText } from '@/components/pms/date-text';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useUnitPreview } from '@/components/pms/reservations/use-unit-preview';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { move, movePreview } from '@/routes/reservations/units';

type Unit = App.Data.Reservations.ReservationUnitData;
type Preview = App.Data.Reservations.UnitMovePreviewData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unit: Unit;
};

function addDays(value: string, days: number): string {
    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(year, month - 1, day + days);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function MoveRoomForm({ open, onOpenChange, reservationId, unit }: Props) {
    const key = { reservation: reservationId, unit: unit.id };
    const businessDate = usePage().props.businessDate?.date ?? unit.arrival;
    const firstNight =
        businessDate > unit.arrival ? businessDate : unit.arrival;
    const lastNight = addDays(unit.departure, -1);
    const form = useForm({
        from_night: firstNight,
        room_type_id: '',
        target_id: '',
        reason: '',
    });
    const { data, errors } = form;
    const { preview } = useUnitPreview<Preview>(
        movePreview.url(key),
        open && data.from_night !== '',
        { from_night: data.from_night, room_type_id: data.room_type_id },
        200,
    );
    const sellsBeds = preview?.sellsBeds ?? false;
    const noun = sellsBeds ? 'Bed' : 'Room';
    // A night with no room yet is a first assignment, not a move.
    const verb = preview?.reasonRequired === false ? 'Assign' : 'Move';
    const field = sellsBeds ? 'bed_id' : 'room_id';
    const targetError = (errors as Record<string, string | undefined>)[field];
    const roomTypeId = data.room_type_id || String(preview?.roomTypeId ?? '');

    const submit = () => {
        form.transform((values) => ({
            from_night: values.from_night,
            [field]: values.target_id,
            reason: values.reason,
        }));
        form.post(move.url(key), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={`${verb} ${noun.toLowerCase()} · ${unit.roomType}`}
            description={
                <>
                    {unit.primaryGuest ?? 'No primary guest'} ·{' '}
                    <DateText date={unit.arrival} /> →{' '}
                    <DateText date={unit.departure} />
                </>
            }
            errors={errors}
            onSubmit={submit}
            submitLabel={`${verb} ${noun.toLowerCase()}`}
            processing={form.processing}
            submitDisabled={
                preview === null ||
                preview.error !== null ||
                data.target_id === '' ||
                (preview.reasonRequired && data.reason.trim() === '')
            }
        >
            <Field data-invalid={!!errors.from_night}>
                <FieldLabel htmlFor="move-from_night">First night</FieldLabel>
                <DatePicker
                    id="move-from_night"
                    value={data.from_night}
                    min={firstNight}
                    max={lastNight}
                    onChange={(value) => {
                        form.setData((current) => ({
                            ...current,
                            from_night: value,
                            target_id: '',
                        }));
                    }}
                    aria-invalid={!!errors.from_night}
                />
                {preview !== null && preview.error === null && (
                    <FieldDescription>
                        {preview.current === null
                            ? `No ${noun.toLowerCase()} assigned on this night.`
                            : `Now in ${preview.current}.`}{' '}
                        Earlier nights stay as they are.
                    </FieldDescription>
                )}
                <FieldError>{errors.from_night}</FieldError>
            </Field>
            {preview?.error != null && (
                <p className="text-sm text-destructive">{preview.error}</p>
            )}
            {preview !== null &&
                preview.error === null &&
                preview.roomTypes.length > 1 && (
                    <Field>
                        <FieldLabel htmlFor="move-room_type_id">
                            Room type
                        </FieldLabel>
                        <EnumSelect
                            id="move-room_type_id"
                            options={preview.roomTypes.map((type) => ({
                                value: String(type.id),
                                label: type.name,
                            }))}
                            value={roomTypeId}
                            onValueChange={(value) =>
                                form.setData((current) => ({
                                    ...current,
                                    room_type_id: value,
                                    target_id: '',
                                }))
                            }
                        />
                        <FieldDescription>
                            Another room type keeps the booked prices and needs
                            the arrival night.
                        </FieldDescription>
                    </Field>
                )}
            {preview !== null && preview.error === null && (
                <Field data-invalid={!!targetError}>
                    <FieldLabel htmlFor="move-target_id">{noun}</FieldLabel>
                    <EnumSelect
                        id="move-target_id"
                        options={preview.units.map((one) => ({
                            value: String(one.id),
                            label: one.label,
                        }))}
                        value={data.target_id}
                        onValueChange={(value) =>
                            form.setData('target_id', value)
                        }
                        placeholder={
                            preview.units.length === 0
                                ? `No free ${noun.toLowerCase()}`
                                : `Choose a ${noun.toLowerCase()}`
                        }
                        disabled={preview.units.length === 0}
                        aria-invalid={!!targetError}
                    />
                    <FieldError>{targetError}</FieldError>
                </Field>
            )}
            <Field data-invalid={!!errors.reason}>
                <FieldLabel htmlFor="move-reason">Reason</FieldLabel>
                <Textarea
                    id="move-reason"
                    value={data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    maxLength={500}
                    rows={2}
                    placeholder={
                        preview?.reasonRequired === false
                            ? 'Optional for a first assignment'
                            : undefined
                    }
                    aria-invalid={!!errors.reason}
                />
                <FieldError>{errors.reason}</FieldError>
            </Field>
        </FormOverlay>
    );
}

/**
 * Move a unit to another room or bed from a night onward. The free rooms or beds come from the preview route for the
 * first night and room type chosen, so the list is never offered a room the server would refuse.
 */
export function MoveRoomDialog(props: Props) {
    return <MoveRoomForm key={useFreshOnOpen(props.open)} {...props} />;
}
