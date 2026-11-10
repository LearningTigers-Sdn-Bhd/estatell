import { router, useForm } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { finish, start } from '@/routes/housekeeping/rooms/cleaning';
import { destroy, store, update } from '@/routes/housekeeping/rooms/cleanings';

type State = App.Data.Housekeeping.RoomCleaningStateData;

/**
 * The housekeeper and the cleaning times of a room, on the Housekeeping board and on House status. Start and Done
 * are one tap and record the time now. When the viewer records for others and the room has no assignee, Start asks
 * who is cleaning. Staff who may correct times get a pencil, unless the page puts that in its row menu. The server
 * says what the viewer may do.
 */
export function CleaningCell({
    state,
    staffOptions,
    assignSlot,
    editInMenu = false,
    allowRestart = true,
    startWarning,
}: {
    state: State;
    staffOptions: EnumOption[];
    /** Shown before Start while no cleaning runs, for example the assignee picker. */
    assignSlot?: ReactNode;
    /** The page opens `CleaningTimesDialog` from its own row menu instead of a pencil here. */
    editInMenu?: boolean;
    /** Offer Start again after a finished cleaning. */
    allowRestart?: boolean;
    /** Start stays possible but quiet, with this reason in a tooltip. */
    startWarning?: string;
}) {
    const [editing, setEditing] = useState(false);
    const { cleaning } = state;
    const isOpen = cleaning !== null && cleaning.outTime === null;
    const isFinished = cleaning !== null && cleaning.outTime !== null;
    const offerNext = !isOpen && (!isFinished || allowRestart);
    const showStart = state.canStart && offerNext;
    const startCleaning = (housekeeperId?: string) =>
        router.post(
            start.url({ room: state.roomId }),
            housekeeperId ? { housekeeper_id: Number(housekeeperId) } : {},
            { preserveScroll: true, preserveState: true },
        );
    const startButton = (
        <Button
            type="button"
            size="sm"
            variant={startWarning ? 'ghost' : 'outline'}
            onClick={state.askHousekeeper ? undefined : () => startCleaning()}
        >
            Start
        </Button>
    );

    return (
        <div className="flex min-w-48 flex-wrap items-center gap-1.5">
            {cleaning && (
                <span className="flex flex-col text-xs">
                    <span className="font-medium">
                        {cleaning.housekeeperName}
                    </span>
                    <span className="text-muted-foreground tabular-nums">
                        {isOpen
                            ? `since ${cleaning.inTime}`
                            : `${cleaning.inTime}–${cleaning.outTime}`}
                        {cleaning.minutes !== null &&
                            ` · ${cleaning.minutes} min`}
                    </span>
                </span>
            )}
            {offerNext && assignSlot}
            {state.canFinish && (
                <Button
                    type="button"
                    size="sm"
                    onClick={() =>
                        router.post(
                            finish.url({ room: state.roomId }),
                            {},
                            { preserveScroll: true, preserveState: true },
                        )
                    }
                >
                    Done
                </Button>
            )}
            {showStart &&
                (state.askHousekeeper ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger render={startButton} />
                        <DropdownMenuContent align="end" className="min-w-48">
                            <DropdownMenuGroup>
                                <DropdownMenuLabel>
                                    Who is cleaning {state.roomNumber}?
                                </DropdownMenuLabel>
                                {staffOptions.map((option) => (
                                    <DropdownMenuItem
                                        key={option.value}
                                        onClick={() =>
                                            startCleaning(option.value)
                                        }
                                    >
                                        {option.label}
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuGroup>
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : startWarning ? (
                    <Tooltip>
                        <TooltipTrigger render={startButton} />
                        <TooltipContent>{startWarning}</TooltipContent>
                    </Tooltip>
                ) : (
                    startButton
                ))}
            {state.canEdit && !editInMenu && (
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
                <CleaningTimesDialog
                    state={state}
                    staffOptions={staffOptions}
                    onClose={() => setEditing(false)}
                />
            )}
        </div>
    );
}

/**
 * Correct, add or remove the cleaning times of a room. For staff with `housekeeping.edit-times`.
 */
export function CleaningTimesDialog({
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
            footerStart={
                cleaning && (
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={form.processing}
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
                        <Trash2 data-icon="inline-start" />
                        Remove
                    </Button>
                )
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
                    <FieldLabel htmlFor="cleaning-in">Started</FieldLabel>
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
                    <FieldLabel htmlFor="cleaning-out">Finished</FieldLabel>
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
        </FormOverlay>
    );
}
