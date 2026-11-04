import { Form, Head, router } from '@inertiajs/react';
import {
    CircleCheck,
    CircleX,
    Clock,
    Ellipsis,
    ExternalLink,
    List,
    MapPin,
    Pencil,
    Plus,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import NearbyPlaceController from '@/actions/App/Http/Controllers/Admin/NearbyPlaceController';
import { EmptyState } from '@/components/pms/empty-state';
import type { EnumOption } from '@/components/pms/enum-select';
import { FilterBar } from '@/components/pms/filter-bar';
import { NearbyPlaceLinkDialog } from '@/components/pms/nearby-place-link-dialog';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { TabLinks } from '@/components/pms/tab-links';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes/admin';
import { index } from '@/routes/admin/nearby-places';

type Place = App.Data.BookingEngine.AdminNearbyPlaceData;

const ALL = 'all';

const statusIcons: Record<string, LucideIcon> = {
    [ALL]: List,
    pending: Clock,
    approved: CircleCheck,
    rejected: CircleX,
};

function act(action: typeof NearbyPlaceController.approve, place: Place) {
    router.put(
        action.url({ nearbyPlace: place.id }),
        {},
        { preserveScroll: true },
    );
}

function RenamePlaceDialog({
    place,
    onClose,
}: {
    place: Place;
    onClose: () => void;
}) {
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Edit nearby place</DialogTitle>
                    <DialogDescription>
                        Guests see this name on every property using it.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...NearbyPlaceController.update.form({
                        nearbyPlace: place.id,
                    })}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field data-invalid={!!errors.name}>
                                <FieldLabel htmlFor="nearby-place-name">
                                    Name
                                </FieldLabel>
                                <Input
                                    id="nearby-place-name"
                                    name="name"
                                    defaultValue={place.name}
                                    maxLength={255}
                                    autoComplete="off"
                                    autoFocus
                                    aria-invalid={!!errors.name}
                                />
                                <FieldError>{errors.name}</FieldError>
                            </Field>
                            <DialogFooter>
                                <DialogClose
                                    render={<Button variant="outline" />}
                                >
                                    Cancel
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    focusableWhenDisabled
                                >
                                    {processing && (
                                        <Spinner data-icon="inline-start" />
                                    )}
                                    Save
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function NearbyPlacesIndex({
    places,
    statusOptions,
    status,
}: {
    places: Place[];
    statusOptions: EnumOption[];
    status: string;
}) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<Place | null>(null);
    const [search, setSearch] = useState('');

    const visible = useMemo(() => {
        const term = search.trim().toLowerCase();

        return places.filter(
            (place) =>
                (status === ALL || place.statusValue === status) &&
                (term === '' || place.name.toLowerCase().includes(term)),
        );
    }, [places, status, search]);

    return (
        <>
            <Head title="Nearby places" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Nearby places"
                    description="The shared list of places. Approved places are suggested to nearby properties. Rejecting a place removes it from every property that has it and tells the owner."
                    actions={
                        <Button onClick={() => setAdding(true)}>
                            <Plus data-icon="inline-start" />
                            Add place
                        </Button>
                    }
                />

                <TabLinks
                    activeTab={status}
                    panelWidth="full"
                    className="gap-4"
                    triggerClassName="min-w-0 px-0 mr-6"
                    panelClassName="flex flex-col gap-4"
                    tabs={[{ value: ALL, label: 'All' }, ...statusOptions].map(
                        (option) => ({
                            value: option.value,
                            label: option.label,
                            icon: statusIcons[option.value] ?? MapPin,
                            href: index({ query: { status: option.value } }),
                        }),
                    )}
                >
                    <FilterBar
                        search={search}
                        onSearchChange={setSearch}
                        searchPlaceholder="Search by name"
                    />

                    <div className="overflow-hidden rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Place</TableHead>
                                    {status === ALL && (
                                        <TableHead>Status</TableHead>
                                    )}
                                    <TableHead className="text-right">
                                        Properties
                                    </TableHead>
                                    <TableHead>Added by</TableHead>
                                    <TableHead>
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {visible.map((place) => (
                                    <TableRow key={place.id}>
                                        <TableCell className="font-medium">
                                            <a
                                                href={place.googleMapsUrl}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex items-center gap-1 hover:underline"
                                            >
                                                {place.name}
                                                <ExternalLink className="size-3" />
                                            </a>
                                        </TableCell>
                                        {status === ALL && (
                                            <TableCell>
                                                <StatusBadge
                                                    status={place.status}
                                                />
                                            </TableCell>
                                        )}
                                        <TableCell className="text-right">
                                            {place.propertiesCount}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {place.addedBy}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center justify-end">
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                        render={
                                                            <Button
                                                                variant="ghost"
                                                                size="icon-sm"
                                                                aria-label={`Actions for ${place.name}`}
                                                            />
                                                        }
                                                    >
                                                        <Ellipsis />
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem
                                                            onClick={() =>
                                                                setEditing(
                                                                    place,
                                                                )
                                                            }
                                                        >
                                                            <Pencil />
                                                            Edit name
                                                        </DropdownMenuItem>
                                                        {place.canApprove && (
                                                            <DropdownMenuItem
                                                                onClick={() =>
                                                                    act(
                                                                        NearbyPlaceController.approve,
                                                                        place,
                                                                    )
                                                                }
                                                            >
                                                                <CircleCheck />
                                                                Approve
                                                            </DropdownMenuItem>
                                                        )}
                                                        {place.canReject && (
                                                            <DropdownMenuItem
                                                                variant="destructive"
                                                                onClick={() =>
                                                                    act(
                                                                        NearbyPlaceController.reject,
                                                                        place,
                                                                    )
                                                                }
                                                            >
                                                                <CircleX />
                                                                Reject
                                                            </DropdownMenuItem>
                                                        )}
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {visible.length === 0 && (
                                    <TableRow className="hover:bg-transparent">
                                        <TableCell
                                            colSpan={status === ALL ? 5 : 4}
                                        >
                                            {search.trim() === '' ? (
                                                <EmptyState
                                                    icon={MapPin}
                                                    title="No places here yet"
                                                    description="Add a place with its Google Maps link. Owners can add places too."
                                                    action={
                                                        <Button
                                                            onClick={() =>
                                                                setAdding(true)
                                                            }
                                                        >
                                                            <Plus data-icon="inline-start" />
                                                            Add place
                                                        </Button>
                                                    }
                                                />
                                            ) : (
                                                <EmptyState
                                                    icon={MapPin}
                                                    title="No places match your search"
                                                    action={
                                                        <Button
                                                            variant="outline"
                                                            onClick={() =>
                                                                setSearch('')
                                                            }
                                                        >
                                                            Clear search
                                                        </Button>
                                                    }
                                                />
                                            )}
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </TabLinks>
            </div>

            <NearbyPlaceLinkDialog
                form={NearbyPlaceController.store.form()}
                description="The place is approved at once and suggested to properties within 25 km."
                open={adding}
                onOpenChange={setAdding}
            />
            {editing && (
                <RenamePlaceDialog
                    key={editing.id}
                    place={editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

NearbyPlacesIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Nearby places', href: index() },
    ],
};
