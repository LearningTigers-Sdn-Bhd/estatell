import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, BedDouble, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/pms/empty-state';
import { EnumSelect } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { ArchiveRoomTypeButton } from '@/components/pms/rooms/archive-room-type-button';
import { GuestsIncludedLabel } from '@/components/pms/rooms/room-type-details-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
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
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import {
    edit,
    index,
    reorder,
    restore,
    store,
} from '@/routes/settings/room-types';

type RoomType = App.Data.Rooms.RoomTypeData;
type SellUnitOption = { value: string; label: string };

type FormData = {
    name: string;
    description: string;
    sell_unit: string;
    max_adults: string;
    max_children: string;
    base_occupancy: string;
    smoking_allowed: boolean;
    pets_allowed: boolean;
    terms_document_id: string;
};

const emptyForm: FormData = {
    name: '',
    description: '',
    sell_unit: 'room',
    max_adults: '2',
    max_children: '0',
    base_occupancy: '2',
    smoking_allowed: false,
    pets_allowed: false,
    terms_document_id: '',
};

/**
 * Create form. A room type is edited on its own page.
 */
function RoomTypeForm({
    sellUnits,
    termsDocuments,
}: {
    sellUnits: SellUnitOption[];
    termsDocuments: SellUnitOption[];
}) {
    const form = useForm<FormData>(emptyForm);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.post(store.url(), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
            <FieldGroup className="flex-1 overflow-y-auto px-4">
                <Field>
                    <FieldLabel htmlFor="room-type-name">Name</FieldLabel>
                    <Input
                        id="room-type-name"
                        maxLength={100}
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        placeholder="e.g. Deluxe King"
                        aria-invalid={!!form.errors.name}
                        autoFocus
                    />
                    <FieldError>{form.errors.name}</FieldError>
                </Field>
                <Field>
                    <FieldLabel htmlFor="room-type-description">
                        Description
                    </FieldLabel>
                    <Textarea
                        id="room-type-description"
                        maxLength={2000}
                        placeholder="e.g. Sea view with a king bed and a balcony"
                        value={form.data.description}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                        aria-invalid={!!form.errors.description}
                    />
                    <FieldError>{form.errors.description}</FieldError>
                </Field>
                <Field>
                    <FieldLabel htmlFor="room-type-sell-unit">
                        Sold by
                    </FieldLabel>
                    <EnumSelect
                        id="room-type-sell-unit"
                        options={sellUnits}
                        value={form.data.sell_unit}
                        onValueChange={(value) =>
                            form.setData('sell_unit', value)
                        }
                        aria-invalid={!!form.errors.sell_unit}
                    />
                    <FieldDescription>
                        A Bed room type sells one bed at a time, for dorms.
                    </FieldDescription>
                    <FieldError>{form.errors.sell_unit}</FieldError>
                </Field>
                <div className="grid grid-cols-3 gap-3">
                    <Field>
                        <FieldLabel htmlFor="room-type-adults">
                            Max adults
                        </FieldLabel>
                        <Input
                            id="room-type-adults"
                            type="number"
                            min={1}
                            max={50}
                            value={form.data.max_adults}
                            onChange={(event) =>
                                form.setData('max_adults', event.target.value)
                            }
                            placeholder="e.g. 2"
                            aria-invalid={!!form.errors.max_adults}
                        />
                        <FieldError>{form.errors.max_adults}</FieldError>
                    </Field>
                    <Field>
                        <FieldLabel htmlFor="room-type-children">
                            Max children
                        </FieldLabel>
                        <Input
                            id="room-type-children"
                            type="number"
                            min={0}
                            max={50}
                            value={form.data.max_children}
                            onChange={(event) =>
                                form.setData('max_children', event.target.value)
                            }
                            placeholder="e.g. 1"
                            aria-invalid={!!form.errors.max_children}
                        />
                        <FieldError>{form.errors.max_children}</FieldError>
                    </Field>
                    <Field>
                        <FieldLabel htmlFor="room-type-included">
                            <GuestsIncludedLabel />
                        </FieldLabel>
                        <Input
                            id="room-type-included"
                            type="number"
                            min={1}
                            value={form.data.base_occupancy}
                            onChange={(event) =>
                                form.setData(
                                    'base_occupancy',
                                    event.target.value,
                                )
                            }
                            placeholder="e.g. 2"
                            aria-invalid={!!form.errors.base_occupancy}
                        />
                        <FieldError>{form.errors.base_occupancy}</FieldError>
                    </Field>
                </div>
                {termsDocuments.length > 0 && (
                    <Field>
                        <FieldLabel htmlFor="room-type-terms">
                            Terms document
                        </FieldLabel>
                        <EnumSelect
                            id="room-type-terms"
                            options={termsDocuments}
                            allLabel="Default"
                            value={form.data.terms_document_id}
                            onValueChange={(value) =>
                                form.setData('terms_document_id', value)
                            }
                            aria-invalid={!!form.errors.terms_document_id}
                        />
                        <FieldDescription>
                            The registration terms guests in this room type
                            sign.
                        </FieldDescription>
                        <FieldError>{form.errors.terms_document_id}</FieldError>
                    </Field>
                )}
                <Field orientation="horizontal">
                    <Switch
                        id="room-type-smoking"
                        checked={form.data.smoking_allowed}
                        onCheckedChange={(checked) =>
                            form.setData('smoking_allowed', checked)
                        }
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="room-type-smoking">
                            Smoking allowed
                        </FieldLabel>
                        <FieldDescription>
                            Guests may smoke in rooms of this type.
                        </FieldDescription>
                    </FieldContent>
                </Field>
                <Field orientation="horizontal">
                    <Switch
                        id="room-type-pets"
                        checked={form.data.pets_allowed}
                        onCheckedChange={(checked) =>
                            form.setData('pets_allowed', checked)
                        }
                    />
                    <FieldContent>
                        <FieldLabel htmlFor="room-type-pets">
                            Pets allowed
                        </FieldLabel>
                        <FieldDescription>
                            Guests may bring pets to rooms of this type.
                        </FieldDescription>
                    </FieldContent>
                </Field>
            </FieldGroup>
            <SheetFooter>
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Create room type
                </Button>
            </SheetFooter>
        </form>
    );
}

export default function RoomTypesIndex({
    roomTypes,
    sellUnits,
    termsDocuments,
}: {
    roomTypes: RoomType[];
    sellUnits: SellUnitOption[];
    termsDocuments: { value: number; label: string }[];
}) {
    const [creating, setCreating] = useState(false);
    const active = roomTypes.filter((roomType) => !roomType.archived);
    const archived = roomTypes.filter((roomType) => roomType.archived);
    const sellUnitLabel = (value: string) =>
        sellUnits.find((option) => option.value === value)?.label ?? value;

    const move = (from: number, to: number) => {
        const order = active.map((roomType) => roomType.id);
        order.splice(to, 0, ...order.splice(from, 1));

        router.put(reorder.url(), { order }, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Room types" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Room types"
                    description="What you sell: a room or a bed. Prices are set on rates, not here."
                    actions={
                        <Button onClick={() => setCreating(true)}>
                            <Plus data-icon="inline-start" />
                            New room type
                        </Button>
                    }
                />

                {active.length === 0 ? (
                    <EmptyState
                        icon={BedDouble}
                        title="No room types yet"
                        description="Add the first room type to start setting up rooms and rates."
                        action={
                            <Button onClick={() => setCreating(true)}>
                                <Plus data-icon="inline-start" />
                                New room type
                            </Button>
                        }
                    />
                ) : (
                    <div className="overflow-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Sold by</TableHead>
                                    <TableHead className="text-right">
                                        <GuestsIncludedLabel />
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Capacity
                                    </TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {active.map((roomType, position) => (
                                    <TableRow key={roomType.id}>
                                        <TableCell className="font-medium">
                                            <Link
                                                href={edit.url({
                                                    roomType: roomType.id,
                                                })}
                                                className="hover:underline"
                                            >
                                                {roomType.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {sellUnitLabel(roomType.sellUnit)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {roomType.baseOccupancy}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {roomType.maxAdults} adults
                                            {roomType.maxChildren > 0 &&
                                                `, ${roomType.maxChildren} children`}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex justify-end gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${roomType.name} up`}
                                                    disabled={position === 0}
                                                    onClick={() =>
                                                        move(
                                                            position,
                                                            position - 1,
                                                        )
                                                    }
                                                >
                                                    <ArrowUp />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Move ${roomType.name} down`}
                                                    disabled={
                                                        position ===
                                                        active.length - 1
                                                    }
                                                    onClick={() =>
                                                        move(
                                                            position,
                                                            position + 1,
                                                        )
                                                    }
                                                >
                                                    <ArrowDown />
                                                </Button>
                                                <ArchiveRoomTypeButton
                                                    roomType={roomType}
                                                />
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                {archived.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <h2 className="font-heading text-sm font-medium">
                            Archived
                        </h2>
                        <div className="overflow-auto rounded-lg border">
                            <Table>
                                <TableBody>
                                    {archived.map((roomType) => (
                                        <TableRow key={roomType.id}>
                                            <TableCell className="font-medium">
                                                <Link
                                                    href={edit.url({
                                                        roomType: roomType.id,
                                                    })}
                                                    className="hover:underline"
                                                >
                                                    {roomType.name}
                                                </Link>{' '}
                                                <Badge variant="secondary">
                                                    Archived
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {sellUnitLabel(
                                                    roomType.sellUnit,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            restore.url({
                                                                roomType:
                                                                    roomType.id,
                                                            }),
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Restore
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </section>
                )}
            </div>

            <Sheet open={creating} onOpenChange={setCreating}>
                <SheetContent className="data-[side=right]:sm:max-w-xl">
                    <SheetHeader>
                        <SheetTitle>New room type</SheetTitle>
                        <SheetDescription>
                            Set what you sell and how many guests it fits.
                        </SheetDescription>
                    </SheetHeader>
                    {creating && (
                        <RoomTypeForm
                            sellUnits={sellUnits}
                            termsDocuments={termsDocuments.map((document) => ({
                                value: String(document.value),
                                label: document.label,
                            }))}
                        />
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}

RoomTypesIndex.layout = {
    breadcrumbs: [{ title: 'Room types', href: index() }],
};
