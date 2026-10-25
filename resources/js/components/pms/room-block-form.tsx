import { useForm } from '@inertiajs/react';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { SheetClose, SheetFooter } from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { store, update } from '@/routes/front-desk/room-blocks';

type Block = App.Data.Rooms.RoomBlockData;
type Room = App.Data.Rooms.RoomBlockRoomData;
type Option = { value: string; label: string };

type FormData = {
    room_id: string;
    bed_id: string;
    reason: App.Enums.RoomBlockReason;
    starts_on: string;
    ends_on: string;
    notes: string;
};

/** A new block for one room, with its bed and nights picked, as the Timeline opens it. */
export type RoomBlockStart = {
    roomId: number;
    bedId: number | null;
    startsOn: string;
    endsOn: string;
};

/**
 * The Block room form inside a Sheet: create a block, or edit the reason, nights and notes of one. The Room blocks
 * page picks the room here. The Timeline passes `start`, so the room is fixed and only the bed can change. Both save
 * through the room block routes, which redirect back to the page that opened the form.
 */
export function RoomBlockForm({
    block,
    start,
    rooms,
    reasons,
    today,
    maxNights,
    onDone,
}: {
    block: Block | null;
    start?: RoomBlockStart;
    rooms: Room[];
    reasons: Option[];
    today: string;
    maxNights: number;
    onDone: () => void;
}) {
    const form = useForm<FormData>({
        room_id: block
            ? String(block.roomId)
            : start
              ? String(start.roomId)
              : '',
        bed_id: block?.bedId
            ? String(block.bedId)
            : start?.bedId
              ? String(start.bedId)
              : '',
        reason: block?.reason ?? 'maintenance',
        starts_on: block?.startsOn ?? start?.startsOn ?? '',
        ends_on: block?.endsOn ?? start?.endsOn ?? '',
        notes: block?.notes ?? '',
    });
    const started = block !== null && block.startsOn <= today;
    const room = rooms.find((item) => String(item.id) === form.data.room_id);
    const roomOptions = rooms.map((item) => ({
        value: String(item.id),
        label: `${item.number} · ${item.roomTypeName}`,
    }));
    const bedOptions = (room?.beds ?? []).map((bed) => ({
        value: String(bed.id),
        label: `Bed ${bed.label}`,
    }));

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: onDone };

        if (block) {
            form.put(update.url({ roomBlock: block.id }), options);
        } else {
            form.post(store.url(), options);
        }
    };

    const bedField = room?.sellsBeds && (
        <Field data-invalid={!!form.errors.bed_id}>
            <FieldLabel htmlFor="block-bed">Bed</FieldLabel>
            <EnumSelect
                id="block-bed"
                name="bed_id"
                options={bedOptions}
                allLabel="Whole room"
                value={form.data.bed_id}
                onValueChange={(value) => form.setData('bed_id', value)}
                aria-invalid={!!form.errors.bed_id}
                className="w-full"
            />
            <FieldError>{form.errors.bed_id}</FieldError>
        </Field>
    );

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <FieldGroup className="flex-1 overflow-y-auto px-4">
                {block ? (
                    <Field>
                        <FieldLabel>Room</FieldLabel>
                        <p className="text-sm">
                            {block.roomNumber} · {block.roomTypeName}
                            {block.bedLabel && `, bed ${block.bedLabel}`}
                        </p>
                        <FieldDescription>
                            To block another room or bed, cancel or end this
                            block and create a new one.
                        </FieldDescription>
                    </Field>
                ) : start ? (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field data-invalid={!!form.errors.room_id}>
                            <FieldLabel>Room</FieldLabel>
                            <p className="text-sm">
                                {room
                                    ? `${room.number} · ${room.roomTypeName}`
                                    : '—'}
                            </p>
                            <FieldError>{form.errors.room_id}</FieldError>
                        </Field>
                        {bedField}
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field data-invalid={!!form.errors.room_id}>
                            <FieldLabel htmlFor="block-room">Room</FieldLabel>
                            <EnumSelect
                                id="block-room"
                                name="room_id"
                                options={roomOptions}
                                value={form.data.room_id}
                                onValueChange={(value) =>
                                    form.setData((data) => ({
                                        ...data,
                                        room_id: value,
                                        bed_id: '',
                                    }))
                                }
                                aria-invalid={!!form.errors.room_id}
                                className="w-full"
                            />
                            <FieldError>{form.errors.room_id}</FieldError>
                        </Field>
                        {bedField}
                    </div>
                )}
                <Field data-invalid={!!form.errors.reason}>
                    <FieldLabel htmlFor="block-reason">Reason</FieldLabel>
                    <EnumSelect
                        id="block-reason"
                        name="reason"
                        options={reasons}
                        value={form.data.reason}
                        onValueChange={(value) =>
                            form.setData(
                                'reason',
                                value as App.Enums.RoomBlockReason,
                            )
                        }
                        aria-invalid={!!form.errors.reason}
                        className="w-full sm:max-w-60"
                    />
                    <FieldError>{form.errors.reason}</FieldError>
                </Field>
                <Field
                    data-invalid={
                        !!(form.errors.starts_on || form.errors.ends_on)
                    }
                >
                    <FieldLabel htmlFor="block-nights">Nights</FieldLabel>
                    <DateRangePicker
                        id="block-nights"
                        from={form.data.starts_on}
                        to={form.data.ends_on}
                        onChange={(startsOn, endsOn) =>
                            form.setData((data) => ({
                                ...data,
                                starts_on: startsOn,
                                ends_on: endsOn,
                            }))
                        }
                        min={today}
                        fromLocked={started}
                        maxDays={maxNights}
                        unit="night"
                        placeholder="Pick the first and last night"
                        aria-invalid={
                            !!(form.errors.starts_on || form.errors.ends_on)
                        }
                    />
                    <FieldDescription>
                        {started
                            ? 'This block has started. You can change its last night.'
                            : 'Both nights are blocked. Pick one day for a single night.'}
                    </FieldDescription>
                    <FieldError>
                        {form.errors.starts_on ?? form.errors.ends_on}
                    </FieldError>
                </Field>
                <Field data-invalid={!!form.errors.notes}>
                    <FieldLabel htmlFor="block-notes">Notes</FieldLabel>
                    <Textarea
                        id="block-notes"
                        rows={3}
                        maxLength={500}
                        placeholder="e.g. Air conditioner repair"
                        value={form.data.notes}
                        onChange={(event) =>
                            form.setData('notes', event.target.value)
                        }
                        aria-invalid={!!form.errors.notes}
                    />
                    <FieldDescription>
                        {form.data.reason === 'other'
                            ? 'Required when the reason is Other.'
                            : 'Optional.'}
                    </FieldDescription>
                    <FieldError>{form.errors.notes}</FieldError>
                </Field>
            </FieldGroup>
            <SheetFooter>
                <SheetClose render={<Button type="button" variant="outline" />}>
                    Cancel
                </SheetClose>
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    {block ? 'Save changes' : 'Block room'}
                </Button>
            </SheetFooter>
        </form>
    );
}
