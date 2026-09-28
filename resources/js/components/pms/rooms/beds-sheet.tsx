import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import {
    archive,
    generate,
    restore,
    store,
    update,
} from '@/routes/settings/rooms/beds';

type Room = App.Data.Rooms.RoomData;
type Bed = App.Data.Rooms.BedData;

/**
 * Rename a bed. Remounted (by key) for each bed, so its state starts from that row.
 */
function RenameBedForm({
    room,
    bed,
    onDone,
}: {
    room: Room;
    bed: Bed;
    onDone: () => void;
}) {
    const form = useForm({ label: bed.label });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.put(update.url({ room: room.id, bed: bed.id }), {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-1">
            <div className="flex gap-2">
                <Input
                    aria-label="Bed label"
                    maxLength={30}
                    value={form.data.label}
                    onChange={(event) =>
                        form.setData('label', event.target.value)
                    }
                    aria-invalid={!!form.errors.label}
                    autoFocus
                />
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Save
                </Button>
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
            </div>
            <FieldError>{form.errors.label}</FieldError>
        </form>
    );
}

/**
 * Ask how many beds to add, then add them to the given rooms (or to every dorm room without beds).
 * With `defaultPrefix` it also asks for a prefix; left empty, the server uses the room's letter.
 */
function GenerateBedsForm({
    roomIds,
    limit,
    defaultCount,
    defaultPrefix,
    submitLabel,
    onDone,
}: {
    roomIds: number[] | null;
    limit: number;
    defaultCount: string;
    defaultPrefix?: string;
    submitLabel: string;
    onDone?: () => void;
}) {
    const form = useForm<{
        count: string;
        prefix: string;
        room_ids: number[] | null;
    }>({
        count: defaultCount,
        prefix: '',
        room_ids: roomIds,
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.post(generate.url(), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('prefix');
                onDone?.();
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <FieldGroup>
                <Field>
                    <FieldLabel htmlFor="bed-count">
                        {roomIds ? 'How many beds' : 'Beds per room'}
                    </FieldLabel>
                    <Input
                        id="bed-count"
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={limit}
                        value={form.data.count}
                        onChange={(event) =>
                            form.setData('count', event.target.value)
                        }
                        aria-invalid={!!form.errors.count}
                    />
                    <FieldDescription>
                        Up to {limit} at a time.
                    </FieldDescription>
                    <FieldError>
                        {form.errors.count ??
                            (form.errors as Record<string, string | undefined>)[
                                'room_ids.0'
                            ]}
                    </FieldError>
                </Field>
                {defaultPrefix !== undefined && (
                    <Field>
                        <FieldLabel htmlFor="bed-prefix">Prefix</FieldLabel>
                        <Input
                            id="bed-prefix"
                            maxLength={10}
                            placeholder={defaultPrefix}
                            value={form.data.prefix}
                            onChange={(event) =>
                                form.setData('prefix', event.target.value)
                            }
                            aria-invalid={!!form.errors.prefix}
                        />
                        <FieldDescription>
                            Labels are the prefix and a number. Leave empty to
                            use this room's letter ({defaultPrefix}). Numbers
                            continue after the last one.
                        </FieldDescription>
                        <FieldError>{form.errors.prefix}</FieldError>
                    </Field>
                )}
            </FieldGroup>
            <div>
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}

/**
 * Add one bed with a label the staff member types.
 */
function ManualBedForm({ room }: { room: Room }) {
    const form = useForm({ label: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.post(store.url({ room: room.id }), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <Field>
                <FieldLabel htmlFor="bed-label">Label</FieldLabel>
                <Input
                    id="bed-label"
                    maxLength={30}
                    placeholder="For example Top bunk"
                    value={form.data.label}
                    onChange={(event) =>
                        form.setData('label', event.target.value)
                    }
                    aria-invalid={!!form.errors.label}
                />
                <FieldError>{form.errors.label}</FieldError>
            </Field>
            <div>
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Add bed
                </Button>
            </div>
        </form>
    );
}

/**
 * Choose how to add beds: generate several from a count and prefix, or add one by typing its label.
 * Remounted (by key) for each room, so the choice starts at Generate.
 */
function AddBedsPanel({
    room,
    limit,
    defaultPrefix,
}: {
    room: Room;
    limit: number;
    defaultPrefix: string;
}) {
    const [mode, setMode] = useState<'generate' | 'manual'>('generate');

    return (
        <FieldSet>
            <FieldLegend variant="label">Add beds</FieldLegend>
            <RadioGroup
                value={mode}
                onValueChange={(value) => setMode(value as typeof mode)}
            >
                <FieldGroup className="gap-3">
                    <Field orientation="horizontal">
                        <RadioGroupItem
                            id="bed-mode-generate"
                            value="generate"
                        />
                        <FieldContent>
                            <FieldLabel htmlFor="bed-mode-generate">
                                Generate
                            </FieldLabel>
                            <FieldDescription>
                                Add several beds at once. Choose how many and a
                                prefix.
                            </FieldDescription>
                        </FieldContent>
                    </Field>
                    <Field orientation="horizontal">
                        <RadioGroupItem id="bed-mode-manual" value="manual" />
                        <FieldContent>
                            <FieldLabel htmlFor="bed-mode-manual">
                                Manual
                            </FieldLabel>
                            <FieldDescription>
                                Add 1 bed and type its label yourself.
                            </FieldDescription>
                        </FieldContent>
                    </Field>
                </FieldGroup>
            </RadioGroup>

            {mode === 'generate' ? (
                <GenerateBedsForm
                    roomIds={[room.id]}
                    limit={limit}
                    defaultCount="4"
                    defaultPrefix={defaultPrefix}
                    submitLabel="Generate beds"
                />
            ) : (
                <ManualBedForm room={room} />
            )}
        </FieldSet>
    );
}

/**
 * The beds of one dorm room: add (generate or manual), rename, archive and restore.
 */
export function BedsSheet({
    room,
    beds,
    prefixes,
    limit,
    onClose,
}: {
    room: Room | null;
    beds: Bed[];
    prefixes: Record<number, string>;
    limit: number;
    onClose: () => void;
}) {
    const [renaming, setRenaming] = useState<number | null>(null);

    const roomBeds = beds.filter((bed) => bed.roomId === room?.id);
    const active = roomBeds.filter((bed) => !bed.archived);
    const archived = roomBeds.filter((bed) => bed.archived);

    const post = (url: string) =>
        router.post(url, {}, { preserveScroll: true });

    return (
        <Sheet open={room !== null} onOpenChange={(open) => !open && onClose()}>
            <SheetContent className="data-[side=right]:sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>Beds in room {room?.number}</SheetTitle>
                    <SheetDescription>
                        Each bed is sold on its own. Labels are how your team
                        names the bed.
                    </SheetDescription>
                </SheetHeader>

                {room && (
                    <div className="flex flex-1 flex-col gap-4 overflow-y-auto px-4">
                        {active.length === 0 ? (
                            <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                No beds yet. Add some below.
                            </p>
                        ) : (
                            <ul className="flex flex-col divide-y rounded-lg border">
                                {active.map((bed) => (
                                    <li
                                        key={bed.id}
                                        className="flex items-center justify-between gap-2 p-2"
                                    >
                                        {renaming === bed.id ? (
                                            <div className="flex-1">
                                                <RenameBedForm
                                                    key={bed.id}
                                                    room={room}
                                                    bed={bed}
                                                    onDone={() =>
                                                        setRenaming(null)
                                                    }
                                                />
                                            </div>
                                        ) : (
                                            <>
                                                <span className="pl-1 font-medium">
                                                    {bed.label}
                                                </span>
                                                <div className="flex gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setRenaming(bed.id)
                                                        }
                                                    >
                                                        Rename
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            post(
                                                                archive.url({
                                                                    room: room.id,
                                                                    bed: bed.id,
                                                                }),
                                                            )
                                                        }
                                                    >
                                                        Archive
                                                    </Button>
                                                </div>
                                            </>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}

                        <AddBedsPanel
                            key={room.id}
                            room={room}
                            limit={limit}
                            defaultPrefix={prefixes[room.id] ?? 'A'}
                        />

                        {archived.length > 0 && (
                            <div className="flex flex-col gap-1">
                                <h3 className="text-sm font-medium">
                                    Archived
                                </h3>
                                <ul className="flex flex-col divide-y rounded-lg border">
                                    {archived.map((bed) => (
                                        <li
                                            key={bed.id}
                                            className="flex items-center justify-between gap-2 p-2"
                                        >
                                            <span className="pl-1">
                                                {bed.label}{' '}
                                                <Badge variant="secondary">
                                                    Archived
                                                </Badge>
                                            </span>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    post(
                                                        restore.url({
                                                            room: room.id,
                                                            bed: bed.id,
                                                        }),
                                                    )
                                                }
                                            >
                                                Restore
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                )}

                <SheetFooter>
                    <SheetClose render={<Button variant="outline" />}>
                        Close
                    </SheetClose>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

/**
 * Add the same number of beds to every dorm room that has none. Each room gets its own letter.
 */
export function GenerateAllBedsSheet({
    open,
    onOpenChange,
    limit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    limit: number;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="data-[side=right]:sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>Add beds to dorm rooms</SheetTitle>
                    <SheetDescription>
                        Every dorm room without beds gets the same number of
                        beds. The first dorm room gets A1, A2 ..., the next B1,
                        B2 ..., and so on. Rooms that already have beds are left
                        alone.
                    </SheetDescription>
                </SheetHeader>
                {open && (
                    <div className="px-4">
                        <GenerateBedsForm
                            roomIds={null}
                            limit={limit}
                            defaultCount="6"
                            submitLabel="Add beds"
                            onDone={() => onOpenChange(false)}
                        />
                    </div>
                )}
            </SheetContent>
        </Sheet>
    );
}
