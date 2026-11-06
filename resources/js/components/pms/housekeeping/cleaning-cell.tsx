import { router, useForm } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { finish, start } from '@/routes/housekeeping/rooms/cleaning';
import { destroy, store, update } from '@/routes/housekeeping/rooms/cleanings';

type State = App.Data.Housekeeping.RoomCleaningStateData;

/**
 * The housekeeper and the IN and OUT time of a room, on the Housekeeping board and on House status. IN and OUT are one
 * tap and record the time now. Staff who may correct times get a pencil. The server says what the viewer may do.
 */
export function CleaningCell({
    state,
    staffOptions,
}: {
    state: State;
    staffOptions: EnumOption[];
}) {
    const [editing, setEditing] = useState(false);
    const { cleaning } = state;
    const post = (url: string) =>
        router.post(url, {}, { preserveScroll: true, preserveState: true });

    return (
        <div className="flex min-w-36 flex-wrap items-center gap-1.5">
            {cleaning && (
                <span className="flex flex-col text-xs">
                    <span className="font-medium">
                        {cleaning.housekeeperName}
                    </span>
                    <span className="text-muted-foreground tabular-nums">
                        IN {cleaning.inTime}
                        {cleaning.outTime && ` · OUT ${cleaning.outTime}`}
                        {cleaning.minutes !== null &&
                            ` · ${cleaning.minutes} min`}
                    </span>
                </span>
            )}
            {state.canFinish && (
                <Button
                    type="button"
                    size="sm"
                    onClick={() => post(finish.url({ room: state.roomId }))}
                >
                    OUT
                </Button>
            )}
            {state.canStart && (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => post(start.url({ room: state.roomId }))}
                >
                    IN
                </Button>
            )}
            {state.canEdit && (
                <Button
                    type="button"
                    size="icon-sm"
                    variant="ghost"
                    aria-label={`Correct the cleaning times of room ${state.roomNumber}`}
                    onClick={() => setEditing(true)}
                >
                    <Pencil aria-hidden />
                </Button>
            )}
            {editing && (
                <CleaningDialog
                    state={state}
                    staffOptions={staffOptions}
                    onClose={() => setEditing(false)}
                />
            )}
        </div>
    );
}

function CleaningDialog({
    state,
    staffOptions,
    onClose,
}: {
    state: State;
    staffOptions: EnumOption[];
    onClose: () => void;
}) {
    const { cleaning } = state;
    const form = useForm({
        housekeeper_id: cleaning ? String(cleaning.housekeeperId) : '',
        in_time: cleaning?.inTime ?? '',
        out_time: cleaning?.outTime ?? '',
    });
    const { errors } = form;
    const done = { preserveScroll: true, onSuccess: onClose };

    const submit = () => {
        if (cleaning) {
            form.put(
                update.url({ room: state.roomId, cleaning: cleaning.id }),
                done,
            );
        } else {
            form.post(store.url({ room: state.roomId }), done);
        }
    };

    return (
        <FormOverlay
            variant="dialog"
            size="sm"
            open
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
            title={`Room ${state.roomNumber}: cleaning times`}
            description={
                cleaning
                    ? `Cleaning of ${cleaning.date}. The change is logged.`
                    : 'Add a cleaning housekeeping did not record. The change is logged.'
            }
            errors={errors}
            onSubmit={submit}
            submitLabel="Save"
            processing={form.processing}
            submitDisabled={
                form.data.housekeeper_id === '' || form.data.in_time === ''
            }
        >
            <Field data-invalid={!!errors.housekeeper_id}>
                <FieldLabel htmlFor="cleaning-housekeeper">
                    Housekeeper
                </FieldLabel>
                <EnumSelect
                    id="cleaning-housekeeper"
                    options={staffOptions}
                    value={form.data.housekeeper_id}
                    onValueChange={(value) =>
                        form.setData('housekeeper_id', value)
                    }
                    aria-invalid={!!errors.housekeeper_id}
                />
                <FieldError>{errors.housekeeper_id}</FieldError>
            </Field>
            <div className="grid grid-cols-2 gap-4">
                <Field data-invalid={!!errors.in_time}>
                    <FieldLabel htmlFor="cleaning-in">IN</FieldLabel>
                    <Input
                        id="cleaning-in"
                        type="time"
                        value={form.data.in_time}
                        aria-invalid={!!errors.in_time}
                        onChange={(event) =>
                            form.setData('in_time', event.target.value)
                        }
                    />
                    <FieldError>{errors.in_time}</FieldError>
                </Field>
                <Field data-invalid={!!errors.out_time}>
                    <FieldLabel htmlFor="cleaning-out">OUT</FieldLabel>
                    <Input
                        id="cleaning-out"
                        type="time"
                        value={form.data.out_time}
                        aria-invalid={!!errors.out_time}
                        onChange={(event) =>
                            form.setData('out_time', event.target.value)
                        }
                    />
                    <FieldError>{errors.out_time}</FieldError>
                </Field>
            </div>
            {cleaning && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="justify-self-start text-destructive"
                    onClick={() =>
                        router.delete(
                            destroy.url({
                                room: state.roomId,
                                cleaning: cleaning.id,
                            }),
                            done,
                        )
                    }
                >
                    <Trash2 aria-hidden />
                    Remove this cleaning
                </Button>
            )}
        </FormOverlay>
    );
}
