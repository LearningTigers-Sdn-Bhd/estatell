import { router, useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { generate } from '@/routes/settings/rooms';
import { preview } from '@/routes/settings/rooms/generate';

type Mode = 'range' | 'floors';

type FormData = {
    mode: Mode;
    room_type_id: string;
    room_group_id: string;
    prefix: string;
    suffix: string;
    from: string;
    to: string;
    floor_from: string;
    floor_to: string;
    room_from: string;
    room_to: string;
};

type PreviewResponse = { rooms: App.Data.Rooms.GeneratedRoomData[] };

const initialData: FormData = {
    mode: 'range',
    room_type_id: '',
    room_group_id: '',
    prefix: '',
    suffix: '',
    from: '',
    to: '',
    floor_from: '1',
    floor_to: '1',
    room_from: '01',
    room_to: '10',
};

function NumberField({
    id,
    label,
    value,
    error,
    onChange,
}: {
    id: keyof FormData;
    label: string;
    value: string;
    error?: string;
    onChange: (value: string) => void;
}) {
    return (
        <Field>
            <FieldLabel htmlFor={`generate-${id}`}>{label}</FieldLabel>
            <Input
                id={`generate-${id}`}
                inputMode="numeric"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                aria-invalid={!!error}
            />
            <FieldError>{error}</FieldError>
        </Field>
    );
}

/**
 * Creates many rooms of one type from a range or from floors. The server builds the preview and
 * checks the numbers, so the browser never generates numbers itself.
 */
export function GenerateRoomsSheet({
    open,
    onOpenChange,
    roomTypes,
    roomGroups,
    limit,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    roomTypes: EnumOption[];
    roomGroups: EnumOption[];
    limit: number;
}) {
    const http = useHttp<FormData, PreviewResponse>(initialData);
    const [rooms, setRooms] = useState<PreviewResponse['rooms']>([]);
    const [saving, setSaving] = useState(false);
    const { data, errors } = http;

    const key = JSON.stringify(data);

    useEffect(() => {
        if (!open || !data.room_type_id) {
            return;
        }

        const timer = window.setTimeout(() => {
            http.clearErrors();
            http.post(preview.url(), {
                onSuccess: (response) => setRooms(response.rooms),
                onError: () => setRooms([]),
            }).catch(() => setRooms([]));
        }, 350);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key, open]);

    const taken = rooms.filter((room) => room.taken);
    const canCreate = rooms.length > 0 && taken.length === 0 && !saving;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        router.post(generate.url(), data, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onError: (formErrors) => http.setError(formErrors),
            onSuccess: () => {
                http.resetAndClearErrors();
                setRooms([]);
                onOpenChange(false);
            },
        });
    };

    const set = <K extends keyof FormData>(field: K, value: FormData[K]) =>
        http.setData((previous) => ({ ...previous, [field]: value }));

    const floors = data.mode === 'floors';
    const groupNames = [
        ...new Set(rooms.map((room) => room.group).filter(Boolean)),
    ];

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="data-[side=right]:sm:max-w-xl">
                <SheetHeader>
                    <SheetTitle>Generate rooms</SheetTitle>
                    <SheetDescription>
                        Create up to {limit} rooms of one room type at once.
                    </SheetDescription>
                </SheetHeader>
                <form
                    onSubmit={submit}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <FieldGroup className="flex-1 overflow-y-auto px-4">
                        <Tabs
                            value={data.mode}
                            onValueChange={(value) =>
                                set('mode', value as Mode)
                            }
                        >
                            <TabsList>
                                <TabsTrigger value="range">Range</TabsTrigger>
                                <TabsTrigger value="floors">Floors</TabsTrigger>
                            </TabsList>
                        </Tabs>
                        <Field>
                            <FieldLabel htmlFor="generate-room_type_id">
                                Room type
                            </FieldLabel>
                            <EnumSelect
                                id="generate-room_type_id"
                                options={roomTypes}
                                value={data.room_type_id}
                                onValueChange={(value) =>
                                    set('room_type_id', value)
                                }
                                aria-invalid={!!errors.room_type_id}
                            />
                            <FieldError>{errors.room_type_id}</FieldError>
                        </Field>
                        {floors ? (
                            <>
                                <div className="grid grid-cols-2 gap-3">
                                    <NumberField
                                        id="floor_from"
                                        label="First floor"
                                        value={data.floor_from}
                                        error={errors.floor_from}
                                        onChange={(v) => set('floor_from', v)}
                                    />
                                    <NumberField
                                        id="floor_to"
                                        label="Last floor"
                                        value={data.floor_to}
                                        error={errors.floor_to}
                                        onChange={(v) => set('floor_to', v)}
                                    />
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <NumberField
                                        id="room_from"
                                        label="First room on each floor"
                                        value={data.room_from}
                                        error={errors.room_from}
                                        onChange={(v) => set('room_from', v)}
                                    />
                                    <NumberField
                                        id="room_to"
                                        label="Last room on each floor"
                                        value={data.room_to}
                                        error={errors.room_to}
                                        onChange={(v) => set('room_to', v)}
                                    />
                                </div>
                                <FieldDescription>
                                    Floor 2 with rooms 01 to 08 makes 201 to
                                    208. Each floor gets its own room group.
                                </FieldDescription>
                            </>
                        ) : (
                            <>
                                <div className="grid grid-cols-2 gap-3">
                                    <NumberField
                                        id="from"
                                        label="From"
                                        value={data.from}
                                        error={errors.from}
                                        onChange={(v) => set('from', v)}
                                    />
                                    <NumberField
                                        id="to"
                                        label="To"
                                        value={data.to}
                                        error={errors.to}
                                        onChange={(v) => set('to', v)}
                                    />
                                </div>
                                <Field>
                                    <FieldLabel htmlFor="generate-room_group_id">
                                        Room group
                                    </FieldLabel>
                                    <EnumSelect
                                        id="generate-room_group_id"
                                        options={roomGroups}
                                        allLabel="No group"
                                        value={data.room_group_id}
                                        onValueChange={(value) =>
                                            set('room_group_id', value)
                                        }
                                    />
                                    <FieldError>
                                        {errors.room_group_id}
                                    </FieldError>
                                </Field>
                            </>
                        )}
                        <div className="grid grid-cols-2 gap-3">
                            <Field>
                                <FieldLabel htmlFor="generate-prefix">
                                    Prefix
                                </FieldLabel>
                                <Input
                                    id="generate-prefix"
                                    maxLength={10}
                                    value={data.prefix}
                                    onChange={(event) =>
                                        set('prefix', event.target.value)
                                    }
                                    aria-invalid={!!errors.prefix}
                                />
                                <FieldError>{errors.prefix}</FieldError>
                            </Field>
                            <Field>
                                <FieldLabel htmlFor="generate-suffix">
                                    Suffix
                                </FieldLabel>
                                <Input
                                    id="generate-suffix"
                                    maxLength={10}
                                    value={data.suffix}
                                    onChange={(event) =>
                                        set('suffix', event.target.value)
                                    }
                                    aria-invalid={!!errors.suffix}
                                />
                                <FieldError>{errors.suffix}</FieldError>
                            </Field>
                        </div>
                        <section
                            className="flex flex-col gap-2"
                            aria-live="polite"
                        >
                            <h3 className="font-heading text-sm font-medium">
                                Preview
                                {rooms.length > 0 && (
                                    <span className="font-normal text-muted-foreground">
                                        {' '}
                                        · {rooms.length} rooms
                                    </span>
                                )}
                            </h3>
                            {rooms.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Choose a room type and fill in the numbers
                                    to see the rooms.
                                </p>
                            ) : (
                                <>
                                    {taken.length > 0 && (
                                        <p className="text-sm text-destructive">
                                            {taken.length} number
                                            {taken.length === 1
                                                ? ' is'
                                                : 's are'}{' '}
                                            already used. Change the numbers to
                                            continue.
                                        </p>
                                    )}
                                    {(groupNames.length > 0
                                        ? groupNames
                                        : [null]
                                    ).map((name) => (
                                        <div
                                            key={name ?? 'all'}
                                            className="flex flex-col gap-1"
                                        >
                                            {name && (
                                                <span className="text-xs text-muted-foreground">
                                                    {name}
                                                </span>
                                            )}
                                            <div className="flex flex-wrap gap-1">
                                                {rooms
                                                    .filter(
                                                        (room) =>
                                                            room.group === name,
                                                    )
                                                    .map((room) => (
                                                        <Badge
                                                            key={room.number}
                                                            variant={
                                                                room.taken
                                                                    ? 'destructive'
                                                                    : 'secondary'
                                                            }
                                                        >
                                                            {room.number}
                                                        </Badge>
                                                    ))}
                                            </div>
                                        </div>
                                    ))}
                                </>
                            )}
                        </section>
                    </FieldGroup>
                    <SheetFooter>
                        <Button
                            type="submit"
                            disabled={!canCreate}
                            focusableWhenDisabled
                        >
                            {saving && <Spinner data-icon="inline-start" />}
                            {rooms.length > 0
                                ? `Create ${rooms.length} rooms`
                                : 'Create rooms'}
                        </Button>
                    </SheetFooter>
                </form>
            </SheetContent>
        </Sheet>
    );
}
