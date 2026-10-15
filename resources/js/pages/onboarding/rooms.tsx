import { Head, router, setLayoutProps, useForm } from '@inertiajs/react';
import { Layers, Plus, Rows3, Trash2, X } from 'lucide-react';
import { useRef, useState } from 'react';
import RoomController from '@/actions/App/Http/Controllers/Onboarding/RoomController';
import Heading from '@/components/heading';
import type { OnboardingLayoutProps } from '@/components/pms/onboarding/onboarding-steps';
import { StepForm } from '@/components/pms/onboarding/step-form';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverDescription,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';

type RoomType = App.Data.Rooms.RoomTypeData;
type Group = App.Data.Rooms.RoomGroupData;
type Room = App.Data.Rooms.RoomData;
type GroupRow = { key: string; id: number | null; name: string };
type RangeRow = {
    room_type_id: string;
    group_key: string;
    prefix: string;
    from: string;
    to: string;
};

const FORM_ID = 'rooms-form';

const countOf = (row: RangeRow) => {
    if (!/^\d{1,6}$/.test(row.from) || !/^\d{1,6}$/.test(row.to)) {
        return 0;
    }

    return Math.max(0, Number(row.to) - Number(row.from) + 1);
};

const isBlank = (row: RangeRow) => !row.prefix && !row.from && !row.to;

export default function Rooms({
    roomTypes,
    groups,
    rooms,
    maxRanges,
    maxGroups,
    locked,
    blockedBy,
}: {
    roomTypes: RoomType[];
    groups: Group[];
    rooms: Room[];
    maxRanges: number;
    maxGroups: number;
    locked: boolean;
    blockedBy: { label: string; url: string | null } | null;
}) {
    const savedGroups = groups.length > 0;
    const counter = useRef(0);
    const newKey = () => `new-${++counter.current}`;
    const typeItems = roomTypes.map((roomType) => ({
        value: String(roomType.id),
        label: roomType.name,
    }));

    const [useGroups, setUseGroups] = useState(savedGroups);
    const startingTypes = roomTypes.filter(
        (roomType) => !rooms.some((room) => room.roomTypeId === roomType.id),
    );
    const firstKey = savedGroups ? `id-${groups[0].id}` : '';
    const form = useForm<{ groups: GroupRow[]; ranges: RangeRow[] }>({
        groups: groups.map((group) => ({
            key: `id-${group.id}`,
            id: group.id,
            name: group.name,
        })),
        ranges: (startingTypes.length > 0
            ? startingTypes
            : [roomTypes[0]].filter(Boolean)
        ).map((roomType) => ({
            room_type_id: String(roomType.id),
            group_key: firstKey,
            prefix: '',
            from: '',
            to: '',
        })),
    });
    const guard = useUnsavedChanges(form.isDirty);
    const { groups: groupRows, ranges } = form.data;
    const errors = form.errors as Record<string, string | undefined>;
    const hasRooms = rooms.length > 0;

    setLayoutProps<OnboardingLayoutProps>({
        step: 'rooms',
        footer: 'auto',
        formId: FORM_ID,
        processing: form.processing,
        width: '7xl',
    });

    const groupName = (key: string) =>
        groupRows.find((group) => group.key === key)?.name || 'New group';

    const setRange = (index: number, values: Partial<RangeRow>) =>
        form.setData(
            'ranges',
            ranges.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );

    const setGroup = (index: number, name: string) =>
        form.setData(
            'groups',
            groupRows.map((row, position) =>
                position === index ? { ...row, name } : row,
            ),
        );

    const changeMode = (value: string) => {
        const on = value === 'groups';

        if (on === useGroups) {
            return;
        }

        setUseGroups(on);
        form.clearErrors();

        if (!on) {
            return;
        }

        const rowsForGroups =
            groupRows.length > 0
                ? groupRows
                : [{ key: newKey(), id: null, name: '' }];
        const first = rowsForGroups[0].key;

        form.setData({
            groups: rowsForGroups,
            ranges: ranges.map((row) => ({
                ...row,
                group_key: row.group_key || first,
            })),
        });
    };

    const removeGroup = (index: number) => {
        const { key } = groupRows[index];

        form.setData({
            groups: groupRows.filter((_, position) => position !== index),
            ranges: ranges.map((row) =>
                row.group_key === key ? { ...row, group_key: '' } : row,
            ),
        });
        form.clearErrors();
    };

    const removeRange = (index: number) => {
        form.setData(
            'ranges',
            ranges.filter((_, position) => position !== index),
        );
        form.clearErrors();
    };

    const save = (onSuccess?: () => void) => {
        form.transform((data) => ({
            use_groups: useGroups,
            groups: useGroups ? data.groups : [],
            ranges: data.ranges,
        }));
        form.put(RoomController.update.url(), {
            preserveScroll: true,
            onSuccess,
        });
    };

    const filled = ranges.filter((row) => !isBlank(row));
    const total = filled.reduce((sum, row) => sum + countOf(row), 0);
    const onlyRange = ranges.length === 1 && !hasRooms;
    const lastGroup = groupRows.length === 1;
    const roomSections = useGroups
        ? groups.map((group) => ({
              id: group.id,
              title: group.name,
              rooms: rooms.filter((room) => room.roomGroupId === group.id),
          }))
        : [{ id: 0, title: '', rooms }];

    return (
        <>
            <Head title="Rooms" />
            <Heading
                title="Rooms"
                description="Which rooms do you have? Enter each run of room numbers once."
            />
            <StepForm
                id={FORM_ID}
                locked={locked}
                blockedBy={blockedBy}
                error={errors.onboarding ?? errors.ranges ?? null}
                onSubmit={(event) => {
                    event.preventDefault();
                    save();
                }}
            >
                <section
                    className="flex flex-col gap-2"
                    aria-labelledby="room-groups"
                >
                    <h3 id="room-groups" className="text-base font-medium">
                        Room groups
                    </h3>
                    <ToggleGroup
                        variant="outline"
                        spacing={0}
                        value={[useGroups ? 'groups' : 'none']}
                        onValueChange={(value) =>
                            value[0] &&
                            changeMode(value[0] === 'groups' ? 'groups' : '')
                        }
                        aria-label="Room groups"
                        disabled={savedGroups}
                        className="w-fit"
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
                    {savedGroups && (
                        <p className="text-sm text-muted-foreground">
                            After you go live, you can remove groups in
                            Settings, under Rooms.
                        </p>
                    )}
                </section>

                {useGroups && (
                    <div className="overflow-hidden rounded-lg border">
                        <Table>
                            <TableHeader className="max-md:hidden">
                                <TableRow>
                                    <TableHead>Group name</TableHead>
                                    <TableHead className="w-12">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {groupRows.map((row, index) => (
                                    <TableRow key={row.key}>
                                        <TableCell className="align-top">
                                            <Input
                                                aria-label={`Name of group ${index + 1}`}
                                                placeholder="e.g. Ground floor"
                                                maxLength={100}
                                                readOnly={row.id !== null}
                                                value={row.name}
                                                onChange={(event) =>
                                                    setGroup(
                                                        index,
                                                        event.target.value,
                                                    )
                                                }
                                                aria-invalid={
                                                    !!errors[
                                                        `groups.${index}.name`
                                                    ]
                                                }
                                            />
                                            <FieldError>
                                                {errors[`groups.${index}.name`]}
                                            </FieldError>
                                        </TableCell>
                                        <TableCell className="align-top">
                                            {row.id === null && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Remove ${row.name || `group ${index + 1}`}`}
                                                    disabled={lastGroup}
                                                    focusableWhenDisabled
                                                    onClick={() =>
                                                        removeGroup(index)
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell colSpan={2}>
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <FieldError>
                                                {errors.groups}
                                            </FieldError>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="ml-auto"
                                                disabled={
                                                    groupRows.length >=
                                                    maxGroups
                                                }
                                                focusableWhenDisabled
                                                onClick={() =>
                                                    form.setData('groups', [
                                                        ...groupRows,
                                                        {
                                                            key: newKey(),
                                                            id: null,
                                                            name: '',
                                                        },
                                                    ])
                                                }
                                            >
                                                <Plus data-icon="inline-start" />
                                                Add group
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </div>
                )}

                <div className="overflow-hidden rounded-lg border">
                    <Table>
                        <TableHeader className="max-md:hidden">
                            <TableRow>
                                <TableHead>Room type</TableHead>
                                {useGroups && <TableHead>Group</TableHead>}
                                <TableHead>Prefix</TableHead>
                                <TableHead>From</TableHead>
                                <TableHead>To</TableHead>
                                <TableHead className="text-right">
                                    Rooms
                                </TableHead>
                                <TableHead className="w-12">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {ranges.map((row, index) => (
                                <TableRow
                                    key={index}
                                    className="max-md:grid max-md:grid-cols-3 max-md:gap-3 max-md:p-3"
                                >
                                    <TableCell className="align-top max-md:col-span-3 max-md:p-0">
                                        <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                            Room type
                                        </span>
                                        <Select
                                            items={typeItems}
                                            value={row.room_type_id}
                                            onValueChange={(value) =>
                                                setRange(index, {
                                                    room_type_id:
                                                        value as string,
                                                })
                                            }
                                        >
                                            <SelectTrigger
                                                aria-label={`Room type of range ${index + 1}`}
                                                aria-invalid={
                                                    !!errors[
                                                        `ranges.${index}.room_type_id`
                                                    ]
                                                }
                                            >
                                                <SelectValue placeholder="Choose a room type" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectGroup>
                                                    {typeItems.map((item) => (
                                                        <SelectItem
                                                            key={item.value}
                                                            value={item.value}
                                                        >
                                                            {item.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectGroup>
                                            </SelectContent>
                                        </Select>
                                        <FieldError>
                                            {
                                                errors[
                                                    `ranges.${index}.room_type_id`
                                                ]
                                            }
                                        </FieldError>
                                    </TableCell>
                                    {useGroups && (
                                        <TableCell className="align-top max-md:col-span-3 max-md:p-0">
                                            <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                Group
                                            </span>
                                            <Select
                                                items={groupRows.map(
                                                    (group) => ({
                                                        value: group.key,
                                                        label:
                                                            group.name ||
                                                            'New group',
                                                    }),
                                                )}
                                                value={row.group_key}
                                                onValueChange={(value) =>
                                                    setRange(index, {
                                                        group_key:
                                                            value as string,
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    aria-label={`Group of range ${index + 1}`}
                                                    aria-invalid={
                                                        !!errors[
                                                            `ranges.${index}.group_key`
                                                        ]
                                                    }
                                                >
                                                    <SelectValue placeholder="Choose a group" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectGroup>
                                                        {groupRows.map(
                                                            (group) => (
                                                                <SelectItem
                                                                    key={
                                                                        group.key
                                                                    }
                                                                    value={
                                                                        group.key
                                                                    }
                                                                >
                                                                    {group.name ||
                                                                        'New group'}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectGroup>
                                                </SelectContent>
                                            </Select>
                                            <FieldError>
                                                {
                                                    errors[
                                                        `ranges.${index}.group_key`
                                                    ]
                                                }
                                            </FieldError>
                                        </TableCell>
                                    )}
                                    <TableCell className="align-top max-md:p-0">
                                        <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                            Prefix
                                        </span>
                                        <Input
                                            aria-label={`Prefix of range ${index + 1}`}
                                            placeholder={
                                                index === 0 ? 'e.g. G' : ''
                                            }
                                            maxLength={10}
                                            className="w-24"
                                            value={row.prefix}
                                            onChange={(event) =>
                                                setRange(index, {
                                                    prefix: event.target.value,
                                                })
                                            }
                                            aria-invalid={
                                                !!errors[
                                                    `ranges.${index}.prefix`
                                                ]
                                            }
                                        />
                                        <FieldError>
                                            {errors[`ranges.${index}.prefix`]}
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top max-md:p-0">
                                        <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                            From
                                        </span>
                                        <Input
                                            aria-label={`First number of range ${index + 1}`}
                                            inputMode="numeric"
                                            placeholder={
                                                index === 0 ? 'e.g. 101' : ''
                                            }
                                            maxLength={6}
                                            className="w-24"
                                            value={row.from}
                                            onChange={(event) =>
                                                setRange(index, {
                                                    from: event.target.value,
                                                })
                                            }
                                            aria-invalid={
                                                !!errors[`ranges.${index}.from`]
                                            }
                                        />
                                        <FieldError>
                                            {errors[`ranges.${index}.from`]}
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="align-top max-md:p-0">
                                        <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                            To
                                        </span>
                                        <Input
                                            aria-label={`Last number of range ${index + 1}`}
                                            inputMode="numeric"
                                            placeholder={
                                                index === 0 ? 'e.g. 110' : ''
                                            }
                                            maxLength={6}
                                            className="w-24"
                                            value={row.to}
                                            onChange={(event) =>
                                                setRange(index, {
                                                    to: event.target.value,
                                                })
                                            }
                                            aria-invalid={
                                                !!errors[`ranges.${index}.to`]
                                            }
                                        />
                                        <FieldError>
                                            {errors[`ranges.${index}.to`]}
                                        </FieldError>
                                    </TableCell>
                                    <TableCell className="text-right align-top tabular-nums max-md:p-0 max-md:text-left">
                                        <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                            Rooms
                                        </span>
                                        <span className="inline-block pt-2">
                                            {countOf(row)}
                                        </span>
                                    </TableCell>
                                    <TableCell className="align-top max-md:col-span-3 max-md:p-0">
                                        <div className="flex justify-end">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                aria-label={`Remove range ${index + 1}`}
                                                disabled={onlyRange}
                                                focusableWhenDisabled
                                                onClick={() =>
                                                    removeRange(index)
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell
                                    colSpan={useGroups ? 7 : 6}
                                    className="max-md:block"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="text-sm">
                                            New rooms: {total}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={
                                                ranges.length >= maxRanges
                                            }
                                            focusableWhenDisabled
                                            onClick={() =>
                                                form.setData('ranges', [
                                                    ...ranges,
                                                    {
                                                        room_type_id: '',
                                                        group_key: useGroups
                                                            ? groupRows[0].key
                                                            : '',
                                                        prefix: '',
                                                        from: '',
                                                        to: '',
                                                    },
                                                ])
                                            }
                                        >
                                            <Plus data-icon="inline-start" />
                                            Add range
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>

                {filled.some((row) => countOf(row) > 0) && (
                    <p className="text-sm text-muted-foreground">
                        <span className="font-medium text-foreground">
                            Preview
                        </span>{' '}
                        {filled
                            .filter((row) => countOf(row) > 0)
                            .map(
                                (row) =>
                                    `${useGroups ? `${groupName(row.group_key)} ` : ''}${row.prefix}${row.from} … ${row.prefix}${row.to}`,
                            )
                            .join(' · ')}
                    </p>
                )}

                {hasRooms && (
                    <section
                        className="flex flex-col gap-3"
                        aria-labelledby="saved-rooms"
                    >
                        <h3 id="saved-rooms" className="text-base font-medium">
                            Saved rooms ({rooms.length})
                        </h3>
                        {roomSections.map((section) => (
                            <div
                                key={section.id}
                                className="flex flex-col gap-2"
                            >
                                {section.title !== '' && (
                                    <h4 className="text-sm text-muted-foreground">
                                        {section.title}
                                    </h4>
                                )}
                                <ul className="flex flex-wrap gap-2">
                                    {section.rooms.map((room) => (
                                        <li key={room.id}>
                                            <Popover>
                                                <PopoverTrigger
                                                    render={
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            aria-label={`Remove room ${room.number}`}
                                                        />
                                                    }
                                                >
                                                    {room.number}
                                                    <X data-icon="inline-end" />
                                                </PopoverTrigger>
                                                <PopoverContent
                                                    align="start"
                                                    className="w-64"
                                                >
                                                    <PopoverDescription>
                                                        Remove room{' '}
                                                        {room.number}?
                                                    </PopoverDescription>
                                                    <Button
                                                        type="button"
                                                        variant="destructive"
                                                        size="sm"
                                                        onClick={() =>
                                                            router.delete(
                                                                RoomController.destroy.url(
                                                                    {
                                                                        room: room.id,
                                                                    },
                                                                ),
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
                                                </PopoverContent>
                                            </Popover>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </section>
                )}

                <p className="text-sm text-muted-foreground">
                    After you go live, you can add floors, suffixes and more
                    rooms in Settings, under Rooms.
                </p>
            </StepForm>
            <UnsavedChangesDialog
                guard={guard}
                name="Rooms"
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}
