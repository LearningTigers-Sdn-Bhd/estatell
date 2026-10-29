import { Head, router, setLayoutProps, useHttp } from '@inertiajs/react';
import { Layers, Plus, Rows3, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import RoomController from '@/actions/App/Http/Controllers/Onboarding/RoomController';
import Heading from '@/components/heading';
import type { OnboardingLayoutProps } from '@/components/pms/onboarding/onboarding-steps';
import { RoomGrid } from '@/components/pms/onboarding/room-grid';
import type { RoomGridSection } from '@/components/pms/onboarding/room-grid';
import { RoomTypePicker } from '@/components/pms/onboarding/room-type-picker';
import { StepForm } from '@/components/pms/onboarding/step-form';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { toast } from '@/components/ui/toast';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import {
    roomTypeColorClass,
    roomTypeShortLabel,
} from '@/lib/room-type-display';

type Tile = App.Data.Onboarding.OnboardingRoomTypeTileData;
type SavedRoom = App.Data.Onboarding.OnboardingRoomData;
type GroupRow = App.Data.Onboarding.OnboardingRoomGroupRowData;
type Parsed = { numbers: string[]; repeated: string[]; error: string | null };
type Pending = { numbers: string[]; parsed: Parsed[]; onSaved?: () => void };

const FORM_ID = 'rooms-form';

const EXAMPLES = ['101-110', '202-216, 218-234', 'G1-G4', 'Villa 3', '01-08'];

const lower = (number: string) => number.toLowerCase();

const plural = (count: number, word: string) =>
    `${count} ${word}${count === 1 ? '' : 's'}`;

export default function Rooms({
    roomTypes,
    groups,
    parsed: savedParsed,
    rooms,
    hasSavedGroups,
    maxGroups,
    maxBeds,
    locked,
    blockedBy,
}: {
    roomTypes: Tile[];
    groups: GroupRow[];
    parsed: Parsed[];
    rooms: SavedRoom[];
    hasSavedGroups: boolean;
    maxGroups: number;
    maxBeds: number;
    locked: boolean;
    blockedBy: { label: string; url: string | null } | null;
}) {
    const counter = useRef(0);
    const http = useHttp<{ groups: GroupRow[] }, { rows: Parsed[] }>({
        groups,
    });
    const savedTypes = Object.fromEntries(
        rooms.map((room) => [lower(room.number), room.roomTypeId]),
    );
    const savedBeds = Object.fromEntries(
        rooms.map((room) => [lower(room.number), room.beds]),
    );
    const startingBeds = Object.fromEntries(
        roomTypes
            .filter((roomType) => roomType.sellUnit === 'bed')
            .map((roomType) => [roomType.id, String(roomType.bedsPerRoom)]),
    );
    const [useGroups, setUseGroups] = useState(hasSavedGroups);
    const [parsed, setParsed] = useState(savedParsed);
    const [types, setTypes] = useState<Record<string, number>>(savedTypes);
    const [beds, setBeds] = useState<Record<number, string>>(startingBeds);
    const [selected, setSelected] = useState(roomTypes[0]?.id ?? 0);
    const [anchor, setAnchor] = useState<{
        section: string;
        index: number;
    } | null>(null);
    const [marking, setMarking] = useState(false);
    const [marked, setMarked] = useState<{
        section: string;
        number: string;
    } | null>(null);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [pending, setPending] = useState<Pending | null>(null);
    const [confirming, setConfirming] = useState(false);
    const dirty =
        http.isDirty ||
        useGroups !== hasSavedGroups ||
        JSON.stringify(types) !== JSON.stringify(savedTypes) ||
        JSON.stringify(beds) !== JSON.stringify(startingBeds);
    const guard = useUnsavedChanges(dirty);
    const rows = useGroups ? http.data.groups : http.data.groups.slice(0, 1);

    setLayoutProps<OnboardingLayoutProps>({
        step: 'rooms',
        footer: 'auto',
        formId: FORM_ID,
        processing: saving,
        width: '7xl',
    });

    const typeIndex = new Map(
        roomTypes.map((roomType, index) => [roomType.id, index]),
    );
    const typeOf = (number: string) =>
        types[lower(number)] ?? roomTypes[0]?.id ?? 0;
    const tileOf = (id: number) => roomTypes[typeIndex.get(id) ?? 0];
    const soldByBed = (id: number) => tileOf(id)?.sellUnit === 'bed';
    const bedsOf = (number: string) =>
        savedBeds[lower(number)] > 0
            ? savedBeds[lower(number)]
            : Number(beds[typeOf(number)] ?? 0);

    const sections: RoomGridSection[] = rows
        .map((row, index) => {
            const result = parsed[index];
            const repeated = new Set((result?.repeated ?? []).map(lower));

            return {
                key: row.key,
                title: useGroups
                    ? row.name || `Group ${index + 1}`
                    : 'All rooms',
                rooms: (result?.numbers ?? []).map((number) => {
                    const typeId = typeOf(number);
                    const tile = tileOf(typeId);

                    return {
                        number,
                        typeName: tile?.name ?? null,
                        shortLabel: tile ? roomTypeShortLabel(tile.name) : '',
                        colorClass: roomTypeColorClass(
                            typeIndex.get(typeId) ?? 0,
                        ),
                        bedText: soldByBed(typeId)
                            ? plural(bedsOf(number), 'bed')
                            : null,
                        repeated: repeated.has(lower(number)),
                    };
                }),
            };
        })
        .filter((section) => section.rooms.length > 0);

    const listed = sections.flatMap((section) =>
        section.rooms.filter((room) => !room.repeated),
    );
    const tiles = roomTypes.map((roomType, index) => {
        const ofType = listed.filter(
            (room) => typeOf(room.number) === roomType.id,
        );

        return {
            id: roomType.id,
            name: roomType.name,
            shortLabel: roomTypeShortLabel(roomType.name),
            colorClass: roomTypeColorClass(index),
            soldByBed: roomType.sellUnit === 'bed',
            rooms: ofType.length,
            beds: ofType.reduce((sum, room) => sum + bedsOf(room.number), 0),
        };
    });
    const total = parsed
        .slice(0, rows.length)
        .reduce((sum, result) => sum + (result?.numbers.length ?? 0), 0);

    const previewKey = JSON.stringify([
        useGroups,
        rows.map((row) => row.numbers),
    ]);
    const previewedKey = useRef(previewKey);

    const preview = () =>
        new Promise<Parsed[]>((resolve) => {
            previewedKey.current = previewKey;
            http.transform((data) => ({
                rows: (useGroups ? data.groups : data.groups.slice(0, 1)).map(
                    (row) => ({ numbers: row.numbers }),
                ),
            }));
            http.post(RoomController.preview.url(), {
                onSuccess: (response) => {
                    setParsed(response.rows);
                    resolve(response.rows);
                },
            }).catch(() => resolve(parsed));
        });

    useEffect(() => {
        if (previewedKey.current === previewKey || locked) {
            return;
        }

        const timer = window.setTimeout(() => void preview(), 300);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [previewKey]);

    const setRow = (index: number, values: Partial<GroupRow>) =>
        http.setData(
            'groups',
            http.data.groups.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );

    const addRow = () => {
        http.setData('groups', [
            ...http.data.groups,
            {
                key: `new-${++counter.current}`,
                id: null,
                name: '',
                numbers: '',
            },
        ]);
        setParsed([...parsed, { numbers: [], repeated: [], error: null }]);
    };

    const removeRow = (index: number) => {
        http.setData(
            'groups',
            http.data.groups.filter((_, position) => position !== index),
        );
        setParsed(parsed.filter((_, position) => position !== index));
        setErrors({});
    };

    const changeMode = (on: boolean) => {
        if (on === useGroups) {
            return;
        }

        if (!on) {
            const numbers = http.data.groups
                .map((row) => row.numbers.trim())
                .filter(Boolean)
                .join(', ');

            http.setData('groups', [{ ...http.data.groups[0], numbers }]);
        }

        setUseGroups(on);
        setErrors({});
    };

    /** Give the rooms a type. A saved room with beds keeps a bed-sold type, so it is left out with a toast. */
    const assign = (numbers: string[], typeId: number) => {
        const refused = numbers.filter(
            (number) => savedBeds[lower(number)] > 0 && !soldByBed(typeId),
        );

        if (refused.length > 0) {
            toast.add({
                title: `${refused[0]} has beds. Pick a type sold by bed.`,
                type: 'error',
            });
        }

        setTypes((current) => ({
            ...current,
            ...Object.fromEntries(
                numbers
                    .filter((number) => !refused.includes(number))
                    .map((number) => [lower(number), typeId]),
            ),
        }));
    };

    const runOf = (sectionKey: string, from: number, to: number) => {
        const section = sections.find((item) => item.key === sectionKey);

        return (section?.rooms ?? [])
            .slice(Math.min(from, to), Math.max(from, to) + 1)
            .filter((room) => !room.repeated)
            .map((room) => room.number);
    };

    const clickRoom = (sectionKey: string, index: number, shift: boolean) => {
        const section = sections.find((item) => item.key === sectionKey);
        const number = section?.rooms[index]?.number;

        if (number === undefined) {
            return;
        }

        if (marking) {
            if (marked === null || marked.section !== sectionKey) {
                setMarked({ section: sectionKey, number });

                return;
            }

            const start = section!.rooms.findIndex(
                (room) => room.number === marked.number,
            );

            assign(runOf(sectionKey, start, index), selected);
            setMarked(null);
            setMarking(false);
        } else if (shift && anchor !== null && anchor.section === sectionKey) {
            assign(runOf(sectionKey, anchor.index, index), selected);
        } else {
            assign([number], selected);
        }

        setAnchor({ section: sectionKey, index });
    };

    const submit = (fresh: Parsed[], onSaved?: () => void) => {
        const numbers = fresh.flatMap((result) => result.numbers);

        router.put(
            RoomController.update.url(),
            {
                use_groups: useGroups,
                groups: rows,
                types: numbers.map((number) => ({
                    number,
                    room_type_id: typeOf(number),
                })),
                beds_per_room: beds,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    setErrors({});
                    http.setDefaults();
                    onSaved?.();
                },
                onError: (next) => {
                    setErrors(next);
                    guard.stay();
                },
            },
        );
    };

    /** Parse the rows once more, then ask first when saved rooms will be archived. */
    const save = async (onSaved?: () => void) => {
        if (locked) {
            return;
        }

        const fresh = await preview();
        const kept = new Set(
            fresh.flatMap((result) => result.numbers).map(lower),
        );
        const archived = rooms
            .map((room) => room.number)
            .filter((number) => !kept.has(lower(number)));

        if (archived.length > 0) {
            setPending({ numbers: archived, parsed: fresh, onSaved });
            setConfirming(true);

            return;
        }

        submit(fresh, onSaved);
    };

    const bedErrors = Object.fromEntries(
        roomTypes.map((roomType) => [
            roomType.id,
            errors[`beds_per_room.${roomType.id}`],
        ]),
    );
    const groupsToggle = (
        <ToggleGroup
            variant="outline"
            spacing={0}
            value={[useGroups ? 'groups' : 'none']}
            onValueChange={(value) =>
                value[0] && changeMode(value[0] === 'groups')
            }
            aria-label="Room groups"
            disabled={hasSavedGroups || locked}
        >
            <ToggleGroupItem value="none">
                <Rows3 data-icon="inline-start" />
                No groups
            </ToggleGroupItem>
            <ToggleGroupItem value="groups">
                <Layers data-icon="inline-start" />
                Use groups
            </ToggleGroupItem>
        </ToggleGroup>
    );
    const onlyRow = rows.length === 1;

    return (
        <>
            <Head title="Rooms" />
            <Heading
                title="Rooms"
                description="Which rooms do you have, and what type is each one?"
            />
            <StepForm
                id={FORM_ID}
                locked={locked}
                blockedBy={blockedBy}
                error={errors.onboarding ?? errors.groups ?? null}
                onSubmit={(event) => {
                    event.preventDefault();
                    void save();
                }}
            >
                <section
                    className="flex flex-col gap-3"
                    aria-labelledby="room-numbers"
                >
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="flex flex-col gap-1">
                            <h3
                                id="room-numbers"
                                className="text-base font-medium"
                            >
                                Room numbers
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                One row per floor, wing or block. Dash for a
                                range, comma between parts.
                            </p>
                        </div>
                        {hasSavedGroups ? (
                            <Tooltip>
                                <TooltipTrigger
                                    render={
                                        <span
                                            tabIndex={0}
                                            className="inline-flex"
                                        />
                                    }
                                >
                                    {groupsToggle}
                                </TooltipTrigger>
                                <TooltipContent>
                                    After you go live, you can remove groups in
                                    Settings, under Rooms.
                                </TooltipContent>
                            </Tooltip>
                        ) : (
                            groupsToggle
                        )}
                    </div>
                    <div className="overflow-hidden rounded-lg border">
                        <Table>
                            <TableHeader className="max-md:hidden">
                                <TableRow>
                                    {useGroups && (
                                        <TableHead className="w-56">
                                            Group
                                        </TableHead>
                                    )}
                                    <TableHead>Room numbers</TableHead>
                                    <TableHead className="w-20 text-right">
                                        Rooms
                                    </TableHead>
                                    <TableHead className="w-12">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row, index) => {
                                    const result = parsed[index];
                                    const numbersError =
                                        errors[`groups.${index}.numbers`] ??
                                        result?.error ??
                                        undefined;

                                    return (
                                        <TableRow
                                            key={row.key}
                                            className="max-md:grid max-md:grid-cols-2 max-md:gap-3 max-md:p-3"
                                        >
                                            {useGroups && (
                                                <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                    <Input
                                                        aria-label={`Name of group ${index + 1}`}
                                                        placeholder="e.g. Floor 1"
                                                        maxLength={100}
                                                        disabled={locked}
                                                        value={row.name}
                                                        onChange={(event) =>
                                                            setRow(index, {
                                                                name: event
                                                                    .target
                                                                    .value,
                                                            })
                                                        }
                                                        aria-invalid={
                                                            !!errors[
                                                                `groups.${index}.name`
                                                            ]
                                                        }
                                                    />
                                                    <FieldError>
                                                        {
                                                            errors[
                                                                `groups.${index}.name`
                                                            ]
                                                        }
                                                    </FieldError>
                                                </TableCell>
                                            )}
                                            <TableCell className="align-top max-md:col-span-2 max-md:p-0">
                                                <Input
                                                    aria-label={`Room numbers of ${useGroups ? row.name || `group ${index + 1}` : 'all rooms'}`}
                                                    placeholder="e.g. 202-216, 218-234"
                                                    maxLength={2000}
                                                    disabled={locked}
                                                    value={row.numbers}
                                                    onChange={(event) =>
                                                        setRow(index, {
                                                            numbers:
                                                                event.target
                                                                    .value,
                                                        })
                                                    }
                                                    aria-invalid={
                                                        !!numbersError
                                                    }
                                                />
                                                <FieldError>
                                                    {numbersError}
                                                </FieldError>
                                            </TableCell>
                                            <TableCell className="text-right align-top tabular-nums max-md:p-0 max-md:text-left md:pt-4">
                                                {result?.error
                                                    ? '—'
                                                    : (result?.numbers.length ??
                                                      0)}
                                            </TableCell>
                                            <TableCell className="align-top max-md:p-0">
                                                <div className="flex justify-end">
                                                    {row.id === null && (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon-sm"
                                                            aria-label={`Remove ${row.name || `group ${index + 1}`}`}
                                                            disabled={
                                                                onlyRow ||
                                                                locked
                                                            }
                                                            focusableWhenDisabled
                                                            onClick={() =>
                                                                removeRow(index)
                                                            }
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell colSpan={useGroups ? 4 : 3}>
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <span className="text-sm">
                                                Total: {plural(total, 'room')}
                                            </span>
                                            {useGroups && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    disabled={
                                                        rows.length >=
                                                            maxGroups || locked
                                                    }
                                                    focusableWhenDisabled
                                                    onClick={addRow}
                                                >
                                                    <Plus data-icon="inline-start" />
                                                    Add group
                                                </Button>
                                            )}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                    <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        <span>Examples:</span>
                        {EXAMPLES.map((example) => (
                            <Badge key={example} variant="outline">
                                {example}
                            </Badge>
                        ))}
                    </div>
                </section>

                <section
                    className="flex flex-col gap-3"
                    aria-labelledby="room-types"
                >
                    <div className="flex flex-col gap-1">
                        <h3 id="room-types" className="text-base font-medium">
                            Room types
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            Pick a type, then click rooms. Shift-click marks
                            every room between.
                        </p>
                    </div>
                    <RoomTypePicker
                        tiles={tiles}
                        value={selected}
                        onValueChange={setSelected}
                        bedsPerRoom={beds}
                        onBedsPerRoomChange={(id, value) =>
                            setBeds((current) => ({ ...current, [id]: value }))
                        }
                        bedErrors={bedErrors}
                        maxBeds={maxBeds}
                        disabled={locked}
                    />
                    <div className="flex flex-wrap justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            aria-pressed={marking}
                            disabled={locked || listed.length === 0}
                            focusableWhenDisabled
                            onClick={() => {
                                setMarking(!marking);
                                setMarked(null);
                            }}
                        >
                            {marking ? 'Tap the last room' : 'Mark a run'}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={locked || listed.length === 0}
                            focusableWhenDisabled
                            onClick={() =>
                                assign(
                                    listed.map((room) => room.number),
                                    selected,
                                )
                            }
                        >
                            Set all to selected type
                        </Button>
                    </div>
                    {sections.length > 0 ? (
                        <RoomGrid
                            sections={sections}
                            marked={marked}
                            disabled={locked}
                            onRoomClick={clickRoom}
                        />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Rooms show here once you enter their numbers.
                        </p>
                    )}
                </section>
            </StepForm>
            <AlertDialog open={confirming} onOpenChange={setConfirming}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Archive{' '}
                            {plural(pending?.numbers.length ?? 0, 'room')}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Save archives{' '}
                            {plural(pending?.numbers.length ?? 0, 'room')}:{' '}
                            {pending?.numbers.join(', ')}.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={saving}
                            focusableWhenDisabled
                            onClick={() => {
                                if (pending !== null) {
                                    submit(pending.parsed, pending.onSaved);
                                }

                                setConfirming(false);
                            }}
                        >
                            {saving && <Spinner />}
                            Save and archive
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            <UnsavedChangesDialog
                guard={guard}
                name="Rooms"
                saving={saving}
                onSave={() => void save(guard.proceed)}
            />
        </>
    );
}
